<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Marvel\Console\MarvelVerification;
use Marvel\Database\Models\Designation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Repositories\UserRepository;
use Marvel\Enums\ModulePermission;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Events\ProcessUserData;
use Marvel\Exceptions\MarvelException;
use Marvel\Exceptions\MarvelNotFoundException;
use Marvel\Http\Requests\ChangePasswordRequest;
use Marvel\Http\Requests\LicenseRequest;
use Marvel\Http\Requests\UserCreateRequest;
use Marvel\Http\Requests\UserUpdateRequest;
use Marvel\Http\Resources\UserResource;
use Marvel\Mail\ContactAdmin;
use Marvel\Otp\Gateways\OtpGateway;
use Marvel\Services\PermissionResolver;
use Marvel\Traits\UsersTrait;
use Marvel\Traits\WalletsTrait;
use Spatie\Newsletter\Facades\Newsletter;

class UserController extends CoreController
{
    use WalletsTrait, UsersTrait;

    public $repository;
    private bool $applicationIsValid;
    private array $appData;

    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
        $this->applicationIsValid = $this->repository->checkIfApplicationIsValid();
    }
    /**
     * Validate user email from the link sent to the user.
     * @param  $id
     * @param  $hash
     * @return RedirectResponse
     */
    public function verifyEmail($id, $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }
        if ($user->hasVerifiedEmail()) {
            if ($user->hasPermissionTo(Permission::SUPER_ADMIN) || $user->hasPermissionTo(Permission::STORE_OWNER)) {
                return Redirect::away(config('shop.dashboard_url'));
            } else {
                return Redirect::away(config('shop.shop_url'));
            }
        }
        $user->markEmailAsVerified();
        if ($user->hasPermissionTo(Permission::SUPER_ADMIN) || $user->hasPermissionTo(Permission::STORE_OWNER)) {
            return Redirect::away(config('shop.dashboard_url'));
        } else {
            return Redirect::away(config('shop.shop_url'));
        }
    }
    /**
     * Send the email verification notification.
     *
     * @return JsonResponse
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->sendEmailVerificationNotification();
        return response()->json(['message' => 'Email verification link sent on your email id', 'success' => true]);
    }


    /**
     * admins
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function admins(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $admins = $this->repository
            ->with(['profile', 'address', 'permissions'])
            ->where('is_active', true)
            ->whereHas('permissions', function ($query) {
                $query->where('name', Permission::SUPER_ADMIN);
            })
            ->paginate($limit);
        return $admins;
        // return UserResource::collection($admins);
    }

    /**
     * F4 — list admin-panel employees (users holding an employee role). Vendors
     * (store_owner) and plain customers are excluded; they have their own screens.
     */
    public function employees(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        // Includes `employee` — the neutral coarse role designation-based
        // employees carry (their capability comes from the materialised perms).
        $employeeRoles = ['super_admin', 'admin', 'manager', 'staff', 'viewer', 'employee'];

        return User::role($employeeRoles)
            ->with(['profile', 'permissions', 'roles', 'designation'])
            ->when($request->name, function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->name}%")
                    ->orWhere('email', 'like', "%{$request->name}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }

    /**
     * F4 — create an employee: an active, email-verified user with one of the
     * assignable roles (super_admin is created only via makeOrRevokeAdmin). The
     * role's module permissions apply via the role; base coarse perms are granted
     * directly so the account can sign into the admin. Super-admin only (route).
     */
    public function storeEmployee(Request $request)
    {
        $request->validate([
            'name'                 => 'required|string|max:255',
            'email'                => 'required|email|unique:users,email',
            'password'             => 'required|string|min:6',
            // Phase B: designation mode preferred; legacy `role` still accepted.
            'role'                 => 'nullable|string|in:admin,manager,staff,viewer',
            'designation_id'       => 'nullable|integer|exists:designations,id',
            'phone'                => 'nullable|string|max:40',
            'department'           => 'nullable|string|max:255',
            'reporting_manager_id' => 'nullable|integer|exists:users,id',
            'city'                 => 'nullable|string|max:255',
            'state'                => 'nullable|string|max:255',
            'permission_source'    => 'nullable|string|in:designation,custom',
            'permission_overrides' => 'nullable|array',
            'is_active'            => 'nullable|boolean',
        ]);

        if (!$request->filled('designation_id') && !$request->filled('role')) {
            throw ValidationException::withMessages([
                'designation_id' => ['Choose a designation (or a legacy role) for the employee.'],
            ]);
        }

        return DB::transaction(function () use ($request) {
            $user = User::create([
                'name'                 => $request->name,
                'email'                => $request->email,
                'password'             => Hash::make($request->password),
                'is_active'            => $request->boolean('is_active', true),
                'department'           => $request->input('department'),
                'reporting_manager_id' => $request->input('reporting_manager_id'),
                'city'                 => $request->input('city'),
                'state'                => $request->input('state'),
            ]);
            $user->email_verified_at = Carbon::now();
            $user->save();

            if ($request->filled('phone')) {
                $user->profile()->create(['contact' => $request->input('phone')]);
            }

            if ($request->filled('designation_id')) {
                // Designation mode: a neutral `employee` role is the coarse login
                // gate (no module perms of its own), and capability is the
                // materialised effective set from designation + overrides.
                $user->syncRoles(['employee']);
                $user->forceFill([
                    'designation_id'       => $request->input('designation_id'),
                    'permission_source'    => $request->input('permission_source', 'designation'),
                    'permission_overrides' => $request->input('permission_overrides'),
                ])->save();
                app(PermissionResolver::class)->materialize($user->fresh());
            } else {
                // Legacy role mode (unchanged): role grants module perms.
                $roleName = $request->input('role');
                $base = ModulePermission::basePermissions()[$roleName] ?? ['staff', 'customer'];
                $user->syncRoles([$roleName]);
                $user->givePermissionTo($base);
            }

            return $user->fresh()->load(['profile', 'permissions', 'roles', 'designation', 'reporting_manager']);
        });
    }

    /**
     * Phase B — a single employee with the data the access editor needs
     * (designation, reporting manager, profile, roles, perms). Gated by
     * employees.view so a non-super-admin employee-manager can load it.
     */
    public function showEmployee($id)
    {
        return User::with([
            'profile',
            'permissions',
            'roles',
            'designation',
            'reporting_manager',
        ])->findOrFail($id);
    }

    /**
     * Phase B — set/replace an existing employee's designation + overrides + org
     * fields, then re-materialise their effective permissions. Super-admin only
     * (route). Super-admin accounts are never re-scoped here (lockout guard).
     */
    public function setEmployeeAccess(Request $request, $id)
    {
        $request->validate([
            'designation_id'       => 'nullable|integer|exists:designations,id',
            'permission_source'    => 'nullable|string|in:designation,custom',
            'permission_overrides' => 'nullable|array',
            'department'           => 'nullable|string|max:255',
            'reporting_manager_id' => 'nullable|integer|exists:users,id',
            'city'                 => 'nullable|string|max:255',
            'state'                => 'nullable|string|max:255',
            'is_active'            => 'nullable|boolean',
        ]);

        $user = User::findOrFail($id);
        if ($user->hasRole('super_admin')) {
            throw new MarvelException(NOT_AUTHORIZED);
        }

        // H1 — prevent privilege escalation: a non-super-admin employee-manager
        // may not edit their OWN access, nor grant access they don't themselves
        // hold (via overrides.grant OR a designation whose defaults exceed theirs).
        $actor = $request->user();
        $actorPerms = $actor ? $actor->getAllPermissions()->pluck('name')->all() : [];
        $isSuper = $actor && ($actor->hasRole('super_admin') || in_array('super_admin', $actorPerms, true));
        if (!$isSuper) {
            if ($actor && (int) $actor->id === (int) $user->id) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            $requested = (array) data_get($request->input('permission_overrides'), 'grant', []);
            if ($request->filled('designation_id')) {
                $desig = \Marvel\Database\Models\Designation::find($request->input('designation_id'));
                $requested = array_merge($requested, (array) ($desig->default_permissions ?? []));
            }
            if (array_diff(array_unique($requested), $actorPerms)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
        }

        return DB::transaction(function () use ($request, $user) {
            $user->forceFill(array_filter([
                'designation_id'       => $request->input('designation_id'),
                'permission_source'    => $request->input('permission_source'),
                'permission_overrides' => $request->input('permission_overrides'),
                'department'           => $request->input('department'),
                'reporting_manager_id' => $request->input('reporting_manager_id'),
                'city'                 => $request->input('city'),
                'state'                => $request->input('state'),
            ], fn ($v) => !is_null($v)));

            if ($request->has('is_active')) {
                $user->is_active = $request->boolean('is_active');
            }
            $user->save();

            // Ensure the coarse login gate + materialise effective perms.
            if (!$user->hasRole('employee') && !$user->hasAnyRole(['admin', 'manager', 'staff', 'viewer', 'super_admin'])) {
                $user->syncRoles(['employee']);
            }
            app(PermissionResolver::class)->materialize($user->fresh());

            return $user->fresh()->load(['profile', 'permissions', 'roles', 'designation', 'reporting_manager']);
        });
    }

    /**
     * F4 — change an existing employee's role. Super-admin accounts can't be
     * re-roled here (use the admin toggle) to avoid privilege confusion / lockout.
     * Super-admin only (route).
     */
    public function assignRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|string|in:admin,manager,staff,viewer',
        ]);

        $user = User::findOrFail($id);
        if ($user->hasRole('super_admin')) {
            throw new MarvelException(NOT_AUTHORIZED);
        }

        // H1 — only a super-admin may assign the broad admin/manager roles or
        // re-role themselves (otherwise an employees.edit holder self-escalates).
        $actor = $request->user();
        $isSuper = $actor && ($actor->hasRole('super_admin') || in_array('super_admin', $actor->getAllPermissions()->pluck('name')->all(), true));
        if (!$isSuper) {
            if ($actor && (int) $actor->id === (int) $user->id) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            if (in_array($request->input('role'), ['admin', 'manager'], true)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
        }

        $roleName = $request->input('role');
        $base = ModulePermission::basePermissions()[$roleName] ?? ['staff', 'customer'];
        $user->syncRoles([$roleName]);
        $user->givePermissionTo($base);

        return $user->load(['profile', 'permissions', 'roles']);
    }

    /**
     * vendors
     *
     * @param  Request $request
     * @return void
     */
    public function vendors(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->fetchVendors($request)->paginate($limit);
    }

    public function fetchVendors(Request $request)
    {
        $user = $request->user();
        $shopId = $request->shop_id ?? null;
        $exclude = is_numeric($request?->exclude) ? $request->exclude : null;
        $is_active = $request->is_active === 'true' ? true : false;
        $admins = User::permission(Permission::SUPER_ADMIN)->pluck('id')->toArray();
        if ($this->repository->hasPermission($user, $shopId)) {
            return $this->repository->permission(Permission::STORE_OWNER)
                ->where('is_active', $is_active)
                ->whereNotIn('id', $admins)
                ->when($exclude, fn ($query) => $query->where('id', '!=', $exclude));
        }
        return $this->repository->permission(null);
    }

    /**
     * customers
     *
     * @param  Request $request
     * @return void
     */
    public function customers(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $userWithOtherPermissions = User::permission([Permission::SUPER_ADMIN, Permission::STORE_OWNER, Permission::STAFF])->pluck('id')->toArray();
        return $this->repository->with(['profile', 'address', 'permissions'])
            ->permission(Permission::CUSTOMER)->whereNotIn('id', $userWithOtherPermissions)->paginate($limit);
    }



    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        return $this->repository->with(['profile', 'address', 'permissions'])->paginate($limit);
    }

    /**
     * Store a newly created resource in storage.
     *Í
     * @param UserCreateRequest $request
     * @return bool[]
     */
    public function store(UserCreateRequest $request)
    {
        try {
            return $this->repository->storeUser($request);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return array
     */
    public function show($id)
    {
        try {
            return $this->repository->with(['profile', 'address', 'shops', 'managed_shop', 'orders', 'providers', 'wallet'])->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UserUpdateRequest $request
     * @param int $id
     * @return array
     */
    public function update(UserUpdateRequest $request, $id)
    {
        if ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            $user = $this->repository->findOrFail($id);
            return $this->repository->updateUser($request, $user);
        } elseif ($request->user()->id == $id) {
            $user = $request->user();
            return $this->repository->updateUser($request, $user);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return array
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();
            if (isset($user)) {
                return $this->repository
                    ->with(['profile', 'wallet', 'address', 'shops.balance', 'managed_shop.balance'])
                    ->find($user->id)
                    ->loadLastOrder();
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    /**
     * PUT me/shopping-city — persist the customer's chosen Shopping City (drives all
     * product discovery; GPS never decides). Validates against the serviceable cities
     * canon (alias-normalized, so "Gurgaon" resolves to "Gurugram") and stores the
     * CANONICAL name in users.preferred_city.
     */
    public function updateShoppingCity(Request $request)
    {
        $data = $request->validate(['city' => 'required|string|max:120']);

        $normalized = app(\Marvel\Services\AvailabilityService::class)
            ->normalizeCityKey($data['city']);
        $canon = \Marvel\Database\Models\City::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
        if (!$canon || !$canon->acceptsOrders()) {
            return response()->json([
                'message' => 'This city is not serviceable yet.',
                'code'    => 'CITY_NOT_SERVICEABLE',
            ], 422);
        }

        $user = $request->user();
        $user->forceFill(['preferred_city' => $canon->name])->save();

        return [
            'preferred_city' => $canon->name,
            'city_id'        => $canon->id,
        ];
    }

    public function token(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (!$user || !Hash::check($request->password, $user->password) || !$this->applicationIsValid) {
            return ["token" => null, "permissions" => []];
        }
        $email_verified = $user->hasVerifiedEmail();
        event(new ProcessUserData());
        return [
            "token" => $user->createToken('auth_token')->plainTextToken,
            // F4: getAllPermissions (direct + via-role) so module permissions
            // granted through the user's role reach the admin for UI gating.
            "permissions" => $user->getAllPermissions()->pluck('name')->unique()->values(),
            "email_verified" => $email_verified,
            "role" => $user->getRoleNames()->first()
        ];
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return true;
        }
        return $request->user()->currentAccessToken()->delete();
    }

    /**
     * Self-serve account deletion (Google Play / App Store requirement). Revokes
     * all tokens, removes the profile + addresses (PII), and anonymises +
     * deactivates the user — order history stays intact but the account is gone
     * and unusable. The authenticated user can only delete their own account.
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $uid = $user->id;
        try { $user->tokens()->delete(); } catch (\Throwable $e) { /* ignore */ }
        try { optional($user->profile)->delete(); } catch (\Throwable $e) { /* ignore */ }
        try { $user->address()->delete(); } catch (\Throwable $e) { /* ignore */ }
        $user->forceFill([
            'name'      => 'Deleted user',
            'email'     => 'deleted+' . $uid . '@plantathome.invalid',
            'is_active' => false,
            'password'  => Hash::make(\Illuminate\Support\Str::random(40)),
        ])->save();
        return response()->json(['message' => 'Your account has been deleted.']);
    }

    public function register(UserCreateRequest $request)
    {
        // Public self-registration may ONLY create a customer (default) or a store_owner
        // (self-serve vendor). Any other requested permission — staff, super_admin, an arbitrary
        // string — is a privilege-escalation attempt and is rejected. The shop's admin approval
        // remains the gate before a store_owner can actually sell.
        $requested = isset($request->permission->value) ? $request->permission->value : ($request->permission ?? null);
        if ($requested !== null && $requested !== Permission::STORE_OWNER) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $permissions = [Permission::CUSTOMER];
        $role = Role::CUSTOMER;
        if ($requested === Permission::STORE_OWNER) {
            $permissions[] = Permission::STORE_OWNER;
            $role = Role::STORE_OWNER;
        }
        $user = $this->repository->create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->giveSignupPointsToCustomer($user->id);
        $setting = Settings::first();
        $useMustVerifyEmail = isset($setting->options['useMustVerifyEmail']) ? $setting->options['useMustVerifyEmail'] : false;
        if ($useMustVerifyEmail) {
            event(new Registered($user));
        }
        event(new ProcessUserData());
        return [
            "token" => $user->createToken('auth_token')->plainTextToken,
            "permissions" => $user->getPermissionNames(),
            "role" => $user->getRoleNames()->first()
        ];
    }

    public function banUser(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN) && $user->id != $request->id) {
                $banUser =  User::find($request->id);
                $banUser->is_active = false;
                $banUser->save();
                $this->inactiveUserShops($banUser->id);
                return $banUser;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
    function inactiveUserShops($userId)
    {
        $shops = Shop::where('owner_id', $userId)->get();
        foreach ($shops as $shop) {
            $shop->is_active = false;
            $shop->save();
            Product::where('shop_id', '=', $shop->id)->update(['status' => 'draft']);
        }
    }

    public function activeUser(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN) && $user->id != $request->id) {
                $activeUser =  User::find($request->id);
                $activeUser->is_active = true;
                $activeUser->save();
                return $activeUser;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function forgetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = $this->repository->findByField('email', $request->email);

        // Only generate + send when the account exists, but ALWAYS return the
        // same response so the endpoint can't be used to enumerate accounts.
        if (count($user) >= 1) {
            // Rotate a fresh, hashed, time-stamped token every request
            // (single-use + expiring — never re-send a stale token).
            DB::table('password_resets')->where('email', $request->email)->delete();
            $token = Str::random(64);
            DB::table('password_resets')->insert([
                'email'      => $request->email,
                'token'      => Hash::make($token),
                'created_at' => Carbon::now(),
            ]);
            $this->repository->sendResetEmail($request->email, $token);
        }

        return ['message' => CHECK_INBOX_FOR_PASSWORD_RESET_EMAIL, 'success' => true];
    }

    /** A reset token is valid only when it matches the row for THAT email and is unexpired. */
    private function isResetTokenValid(string $email, string $token): bool
    {
        $row = DB::table('password_resets')->where('email', $email)->first();
        if (!$row || !Hash::check($token, $row->token)) {
            return false;
        }
        $expire = (int) config('auth.passwords.users.expire', 60);
        return !Carbon::parse($row->created_at)->addMinutes($expire)->isPast();
    }

    public function verifyForgetPasswordToken(Request $request)
    {
        $request->validate(['email' => 'required|email', 'token' => 'required|string']);
        if (!$this->isResetTokenValid($request->email, $request->token)) {
            return ['message' => INVALID_TOKEN, 'success' => false];
        }
        return ['message' => TOKEN_IS_VALID, 'success' => true];
    }
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'password' => ['required', 'string', Password::min(8)],
                'email'    => ['required', 'email'],
                'token'    => ['required', 'string'],
            ]);

            // The token MUST match the row for this email and be unexpired —
            // otherwise anyone could reset any account by supplying its email.
            if (!$this->isResetTokenValid($request->email, $request->token)) {
                return ['message' => INVALID_TOKEN, 'success' => false];
            }

            $user = $this->repository->where('email', $request->email)->first();
            if (!$user) {
                return ['message' => NOT_FOUND, 'success' => false];
            }
            $user->password = Hash::make($request->password);
            // Completing an emailed-token reset proves mailbox ownership — mark verified.
            if (is_null($user->email_verified_at)) {
                $user->email_verified_at = now();
            }
            $user->save();

            // Single-use: invalidate the token after a successful reset.
            DB::table('password_resets')->where('email', $request->email)->delete();

            return ['message' => PASSWORD_RESET_SUCCESSFUL, 'success' => true];
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Exception $th) {
            return ['message' => SOMETHING_WENT_WRONG, 'success' => false];
        }
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        try {
            $user = $request->user();
            if (Hash::check($request->oldPassword, $user->password)) {
                $user->password = Hash::make($request->newPassword);
                $user->save();
                return ['message' => PASSWORD_RESET_SUCCESSFUL, 'success' => true];
            } else {
                return ['message' => OLD_PASSWORD_INCORRECT, 'success' => false];
            }
        } catch (\Exception $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
    public function contactAdmin(Request $request)
    {
        try {
            $listedAdmin = [];
            $admins = $this->getAdminUsers();
            if (isset($admins)) {
                foreach ($admins as $key => $admin) {
                    array_push($listedAdmin, $admin->email);
                }
            }
            $details = $request->only('subject', 'name', 'email', 'description');
            // SECURITY: ALWAYS send to the server-resolved admin list — never a client-supplied
            // emailTo (that turned this public endpoint into an open mail relay to any recipient).
            $emailTo = $listedAdmin;
            if (empty($emailTo)) {
                return ['message' => EMAIL_SENT_SUCCESSFUL, 'success' => true];
            }
            Mail::to($emailTo)->send(new ContactAdmin($details));
            return ['message' => EMAIL_SENT_SUCCESSFUL, 'success' => true];
        } catch (\Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function fetchStaff(Request $request)
    {
        try {
            if (!isset($request->shop_id)) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->with(['profile'])->where('shop_id', '=', $request->shop_id);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function staffs(Request $request)
    {
        $query = $this->fetchStaff($request);
        $limit = $request->limit ?? 15;
        return $query->paginate($limit);
    }

    public function socialLogin(Request $request)
    {
        $provider = $request->provider;
        $token = $request->access_token;
        $this->validateProvider($provider);

        try {
            $normalized = $this->fetchSocialUser($provider, $token);
            return $this->issueSocialToken($provider, $normalized);
        } catch (\Exception $e) {
            throw new MarvelException(INVALID_CREDENTIALS);
        }
    }

    /**
     * App-side LinkedIn login: LinkedIn requires the client secret for the code→token
     * exchange (no PKCE-only public clients), so the native app sends the auth code here
     * and the secret stays server-side. Web uses NextAuth and posts the access_token to
     * socialLogin() directly.
     */
    public function linkedinExchange(Request $request)
    {
        try {
            $resp = \Illuminate\Support\Facades\Http::asForm()->timeout(8)->connectTimeout(3)->post('https://www.linkedin.com/oauth/v2/accessToken', array_filter([
                'grant_type'    => 'authorization_code',
                'code'          => $request->code,
                'redirect_uri'  => $request->redirect_uri ?: config('services.linkedin.redirect'),
                'client_id'     => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
                'code_verifier' => $request->code_verifier,
            ]));
            $accessToken = $resp->json('access_token');
            if (!$accessToken) {
                throw new \Exception('LinkedIn token exchange failed');
            }
            $normalized = $this->fetchSocialUser('linkedin', $accessToken);
            return $this->issueSocialToken('linkedin', $normalized);
        } catch (\Exception $e) {
            throw new MarvelException(INVALID_CREDENTIALS);
        }
    }

    /** Normalize a provider's user into {id, email, name, avatar}. */
    protected function fetchSocialUser($provider, $token)
    {
        if ($provider === 'linkedin') {
            // OIDC ("Sign In with LinkedIn using OpenID Connect") userinfo claims.
            $resp = \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()
                ->timeout(8)->connectTimeout(3)
                ->get('https://api.linkedin.com/v2/userinfo');
            if (!$resp->ok()) {
                throw new \Exception('LinkedIn userinfo failed');
            }
            $d = $resp->json();
            return [
                'id'     => $d['sub'] ?? null,
                'email'  => $d['email'] ?? null,
                'name'   => $d['name'] ?? trim(($d['given_name'] ?? '') . ' ' . ($d['family_name'] ?? '')),
                'avatar' => $d['picture'] ?? null,
            ];
        }
        $u = Socialite::driver($provider)->userFromToken($token);
        return [
            'id'     => $u->getId(),
            'email'  => $u->getEmail(),
            'name'   => $u->getName(),
            'avatar' => $u->getAvatar(),
        ];
    }

    /** Find-or-create the customer for a normalized social user and issue an auth token. */
    protected function issueSocialToken($provider, array $u)
    {
        if (empty($u['email'])) {
            throw new \Exception('Email not provided by ' . $provider);
        }
        $userExist = User::where('email', $u['email'])->exists();

        $userCreated = User::firstOrCreate(
            ['email' => $u['email']],
            ['email_verified_at' => now(), 'name' => $u['name'] ?? '']
        );

        $userCreated->providers()->updateOrCreate([
            'provider' => $provider,
            'provider_user_id' => $u['id'],
        ]);

        $avatar = ['thumbnail' => $u['avatar'], 'original' => $u['avatar']];
        // Match on the relation FK (one profile per user) — matching on the
        // avatar JSON spawned a new profile row on every social login.
        $userCreated->profile()->updateOrCreate(
            ['customer_id' => $userCreated->id],
            ['avatar' => $avatar]
        );

        if (!$userCreated->hasPermissionTo(Permission::CUSTOMER)) {
            $userCreated->givePermissionTo(Permission::CUSTOMER);
            $userCreated->assignRole(Role::CUSTOMER);
        }

        if (empty($userExist)) {
            $this->giveSignupPointsToCustomer($userCreated->id);
        }
        event(new ProcessUserData());
        return [
            "token" => $userCreated->createToken('auth_token')->plainTextToken,
            "permissions" => $userCreated->getPermissionNames(),
            "role" => $userCreated->getRoleNames()->first()
        ];
    }

    protected function validateProvider($provider)
    {
        if (!in_array($provider, ['facebook', 'google', 'linkedin'])) {
            throw new MarvelException(PLEASE_LOGIN_USING_FACEBOOK_OR_GOOGLE);
        }
    }

    /**
     * Resolve the OTP gateway name for an optional per-request channel. Lets a client offer
     * both SMS and WhatsApp login regardless of the global ACTIVE_OTP_GATEWAY default.
     * Accepts 'sms' / 'whatsapp' aliases or an explicit gateway name; falls back to the default.
     */
    protected function resolveOtpGatewayName($channel = null)
    {
        $allowed = ['twilio', 'msg91', 'messagebird', 'whatsapp'];
        if ($channel === 'whatsapp') {
            return 'whatsapp';
        }
        if ($channel === 'sms') {
            return config('auth.sms_otp_gateway') ?: config('auth.active_otp_gateway');
        }
        if (is_string($channel) && in_array($channel, $allowed, true)) {
            return $channel;
        }
        return config('auth.active_otp_gateway');
    }

    protected function getOtpGateway($channel = null)
    {
        $gateway = $this->resolveOtpGatewayName($channel);
        $gateWayClass = "Marvel\\Otp\\Gateways\\" . ucfirst($gateway) . 'Gateway';
        return new OtpGateway(new $gateWayClass());
    }

    protected function verifyOtp(Request $request)
    {
        $id = $request->otp_id;
        $code = $request->code;
        $phoneNumber = $request->phone_number;
        try {
            // Verify with the SAME gateway that sent the code (client echoes the channel back).
            $otpGateway = $this->getOtpGateway($request->channel);
            $verifyOtpCode = $otpGateway->checkVerification($id, $code, $phoneNumber);
            if ($verifyOtpCode->isValid()) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function sendOtpCode(Request $request)
    {
        $phoneNumber = $request->phone_number;
        // Optional per-request channel ('sms' | 'whatsapp' | explicit gateway). The resolved
        // gateway name is echoed back so the client sends it again on verify/login.
        $channel = $request->channel;
        try {
            $gatewayName = $this->resolveOtpGatewayName($channel);
            $otpGateway = $this->getOtpGateway($channel);
            $sendOtpCode = $otpGateway->startVerification($phoneNumber);
            if (!$sendOtpCode->isValid()) {
                return ['message' => OTP_SEND_FAIL, 'success' => false];
            }
            return [
                'message' => OTP_SEND_SUCCESSFUL,
                'success' => true,
                'provider' => $gatewayName,
                'channel' => $channel,
                'id' => $sendOtpCode->getId(),
                'phone_number' => $phoneNumber,
                // Do NOT disclose whether the phone is registered (account enumeration).
            ];
        } catch (MarvelException $e) {
            throw new MarvelException(INVALID_GATEWAY);
        }
    }

    public function verifyOtpCode(Request $request)
    {
        try {
            if ($this->verifyOtp($request)) {
                return [
                    "message" => OTP_SEND_SUCCESSFUL,
                    "success" => true,
                ];
            }
            throw new MarvelException(OTP_VERIFICATION_FAILED);
        } catch (\Throwable $e) {
            throw new MarvelException(OTP_VERIFICATION_FAILED);
        }
    }

    public function otpLogin(Request $request)
    {
        $phoneNumber = $request->phone_number;

        try {
            if ($this->verifyOtp($request)) {
                // check if phone number exist
                $profile = Profile::where('contact', $phoneNumber)->first();
                $user = '';
                if (!$profile) {
                    // profile not found so could be a new user
                    $name = $request->name;
                    $email = $request->email;
                    if ($name && $email) {
                        $userExist = User::where('email',  $email)->exists();
                        $user = User::firstOrCreate([
                            'email'     => $email
                        ], [
                            'name'    => $name,
                        ]);
                        $user->givePermissionTo(Permission::CUSTOMER);
                        $user->assignRole(Role::CUSTOMER);

                        $user->profile()->updateOrCreate(
                            ['customer_id' => $user->id],
                            [
                                'contact' => $phoneNumber
                            ]
                        );
                        if (empty($userExist)) {
                            $this->giveSignupPointsToCustomer($user->id);
                        }
                    } else {
                        return ['message' => REQUIRED_INFO_MISSING, 'success' => false];
                    }
                } else {
                    $user = User::where('id', $profile->customer_id)->first();
                }
                event(new ProcessUserData());
                return [
                    "token" => $user->createToken('auth_token')->plainTextToken,
                    "permissions" => $user->getPermissionNames(),
                    "role" => $user->getRoleNames()->first()
                ];
            }
            return ['message' => OTP_VERIFICATION_FAILED, 'success' => false];
        } catch (\Throwable $e) {
            return response()->json(['error' => INVALID_GATEWAY], 422);
        }
    }

    public function updateContact(Request $request)
    {
        $phoneNumber = $request->phone_number;
        // SECURITY: always bind to the AUTHENTICATED user — never a client-supplied user_id.
        // Trusting $request->user_id let any logged-in customer verify their own phone OTP and
        // then write that contact onto an arbitrary victim's Profile, enabling phone-OTP login
        // takeover of that account.
        $user = $request->user();
        if (!$user) {
            return ['message' => CONTACT_UPDATE_FAILED, 'success' => false];
        }

        try {
            if ($this->verifyOtp($request)) {
                $user->profile()->updateOrCreate(
                    ['customer_id' => $user->id],
                    [
                        'contact' => $phoneNumber
                    ]
                );
                return [
                    "message" => CONTACT_UPDATE_SUCCESSFUL,
                    "success" => true,
                ];
            }
            return ['message' => CONTACT_UPDATE_FAILED, 'success' => false];
        } catch (\Exception $e) {
            return response()->json(['error' => INVALID_GATEWAY], 422);
        }
    }

    public function addPoints(Request $request)
    {
        $request->validate([
            'points' => 'required|numeric',
            'customer_id' => ['required', 'exists:Marvel\Database\Models\User,id']
        ]);
        $points = $request->points;
        $customer_id = $request->customer_id;

        $wallet = Wallet::firstOrCreate(['customer_id' => $customer_id]);
        $wallet->total_points = $wallet->total_points + $points;
        $wallet->available_points = $wallet->available_points + $points;
        $wallet->save();
    }

    public function makeOrRevokeAdmin(Request $request)
    {
        $user = $request->user();
        if ($this->repository->hasPermission($user)) {
            $user_id = $request->user_id;
            try {
                $newUser = $this->repository->findOrFail($user_id);
                if ($newUser->hasPermissionTo(Permission::SUPER_ADMIN)) {
                    $newUser->revokePermissionTo(Permission::SUPER_ADMIN);
                    $newUser->removeRole(Role::SUPER_ADMIN);
                    return true;
                }
            } catch (Exception $e) {
                throw new MarvelException(USER_NOT_FOUND);
            }
            $newUser->givePermissionTo(Permission::SUPER_ADMIN);
            $newUser->assignRole(Role::SUPER_ADMIN);

            return true;
        }

        throw new MarvelException(NOT_AUTHORIZED);
    }
    public function subscribeToNewsletter(Request $request)
    {
        try {
            $email = $request->email;
            Newsletter::subscribeOrUpdate($email);
            return true;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
    public function updateUserEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
        ]);
        if ($validator->fails()) {
            throw new MarvelException($validator->errors()->first());
        }
        // A user's primary email is immutable once set — it's established at
        // signup and can only be verified (via email OTP), never changed.
        $user = $request->user();
        if ($user && $user->email && strtolower(trim($request->email)) !== strtolower($user->email)) {
            throw new MarvelException('Your email is already set and cannot be changed.');
        }
        return $this->repository->updateEmail($request);
    }

    public function myStaffs(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        return $this->fetchMyStaffs($request)->paginate($limit);
    }
    public function fetchMyStaffs(Request $request)
    {
        $user = $request->user();
        if ($this->repository->hasPermission($user, $request->shop_id)) {
            return $this->repository->whereRelation('managed_shop', 'owner_id', '=', $user->id);
        }
        return $this->repository->whereRelation('managed_shop', 'owner_id', '=', null);
    }

    public function allStaffs(Request $request)
    {
        $user = $request->user();
        $limit = $request->limit ? $request->limit : 15;
        if ($this->repository->hasPermission($user)) {
            return $this->repository->permission(Permission::STAFF)->paginate($limit);
        }
        return $this->repository->permission(null)->paginate($limit);
    }


    public function verifyLicenseKey(LicenseRequest $request, MarvelVerification $verification)
    {
        try {
            $licenseKey = $request->license_key;
            $language = $request['language'] ?? DEFAULT_LANGUAGE;
            $marvel = $verification->verify($licenseKey);
            if (!$marvel->getTrust()) {
                throw new MarvelNotFoundException(INVALID_LICENSE_KEY);
            }
            return $marvel->modifySettingsData($language);
        } catch (MarvelException $th) {
            throw new MarvelException(INVALID_LICENSE_KEY);
        }
    }
    public function fetchUsersByPermission(Request $request)
    {
        $user = $request->user() ?? null;
        $permission = strtolower($request->permission) ?? true;
        $is_active = $request->is_active ?? true;
        $query = $this->repository->where('is_active', $is_active);
        if (!$this->repository->hasPermission($user, $request->shop_id)) {
            return $query->permission(null);
        }
        switch ($permission) {
            case Permission::SUPER_ADMIN:
                $query->permission($permission);
                break;
            case Permission::STORE_OWNER:
                $excludeUsers = User::permission(Permission::SUPER_ADMIN)->pluck('id')->toArray();
                if(isset($request->exclude)){
                    $excludeUsers = [...$excludeUsers, $request->exclude];
                }
                $query->permission($permission)->whereNotIn('id', $excludeUsers);
                break;
            case Permission::STAFF:
                $query->permission($permission);
                break;
            case Permission::CUSTOMER:
                $excludeUsers = User::permission([Permission::SUPER_ADMIN, Permission::STORE_OWNER, Permission::STAFF])
                    ->pluck('id')->toArray();
                $query->permission($permission)->whereNotIn('id', $excludeUsers);
                break;
            default:
                $query->permission(null);
                break;
        }
        return $query;
    }
}
