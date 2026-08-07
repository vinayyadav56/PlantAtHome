<?php

namespace Marvel\Database\Repositories;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use Prettus\Validator\Exceptions\ValidatorException;
use Spatie\Permission\Models\Permission;
use Marvel\Enums\Permission as UserPermission;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Marvel\Mail\ForgetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Arr;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Exceptions\MarvelException;

class UserRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'email' => 'like',
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'name',
        'email',
        // 'shop_id' intentionally NOT self-assignable: a customer could PUT their own id with a
        // victim shop's id and then read/post that shop's private conversations (chat authz
        // trusts user->shop_id). Staff↔shop linkage is set via the admin staff endpoints only.
    ];

    /**
     * Configure the Model
     **/
    public function model()
    {
        return User::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    public function storeUser($request)
    {
        try {
            $user = $this->create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $user->givePermissionTo(UserPermission::CUSTOMER);
            if (isset($request['address']) && count($request['address'])) {
                $user->address()->createMany($request['address']);
            }
            if (isset($request['profile'])) {
                $user->profile()->create($request['profile']);
            }
            $user->profile = $user->profile;
            $user->address = $user->address;
            $user->shop = $user->shop;
            $user->managed_shop = $user->managed_shop;
            return $user;
        } catch (ValidatorException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Derive rg_* (reverse-geocoded city/district/state/pincode) server-side from the
     * address's map-pin coordinates. Fail-open: faults leave rg_* absent and the
     * checkout gate falls back to the postal master by pincode.
     */
    private function withReverseGeocode(array $fields): array
    {
        $lat = isset($fields['latitude']) ? (float) $fields['latitude'] : null;
        $lng = isset($fields['longitude']) ? (float) $fields['longitude'] : null;
        if (!$lat || !$lng) {
            return $fields;
        }
        try {
            $rg = app(\Marvel\Services\ReverseGeocodeService::class)->resolve($lat, $lng);
            $fields['rg_city']     = $rg['city'] ?? null;
            $fields['rg_district'] = $rg['district'] ?? null;
            $fields['rg_state']    = $rg['state'] ?? null;
            $fields['rg_pincode']  = $rg['pincode'] ?? null;
        } catch (\Throwable $e) {
            // fail-open
        }
        return $fields;
    }

    public function updateUser($request, $user)
    {
        try {
            // SECURITY: scope every address row to the user being updated and never accept a
            // client-supplied id/customer_id (prevents overwriting/reparenting another user's
            // Address via a guessed id — an IDOR + mass-assignment hole).
            if (isset($request['address']) && count($request['address'])) {
                foreach ($request['address'] as $address) {
                    // rg_* are SERVER-derived from the map-pin coordinates (Shopping-City gate
                    // trusts them) — never accept client values.
                    $fields = Arr::except((array) $address, [
                        'id', 'customer_id', 'created_at', 'updated_at',
                        'rg_city', 'rg_district', 'rg_state', 'rg_pincode',
                    ]);
                    $fields = $this->withReverseGeocode($fields);
                    if (isset($address['id'])) {
                        $owned = Address::where('customer_id', $user->id)->find($address['id']);
                        if ($owned) {
                            $owned->update($fields);
                        }
                    } else {
                        $fields['customer_id'] = $user->id;
                        Address::create($fields);
                    }
                }
            }

            // SECURITY: same ownership scope + strip id/customer_id for the profile row.
            if (isset($request['profile'])) {
                $profileFields = Arr::except((array) $request['profile'], ['id', 'customer_id', 'created_at', 'updated_at']);
                if (isset($request['profile']['id'])) {
                    $ownedProfile = Profile::where('customer_id', $user->id)->find($request['profile']['id']);
                    if ($ownedProfile) {
                        $ownedProfile->update($profileFields);
                    } else {
                        $profileFields['customer_id'] = $user->id;
                        Profile::updateOrCreate(['customer_id' => $user->id], $profileFields);
                    }
                } else {
                    $profileFields['customer_id'] = $user->id;
                    Profile::updateOrCreate(['customer_id' => $user->id], $profileFields);
                }
            }
            $user->update($request->only($this->dataArray));
            $user->profile = $user->profile;
            $user->address = $user->address;
            $user->shop = $user->shop;
            $user->managed_shop = $user->managed_shop;
            return $user;
        } catch (ValidationException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function sendResetEmail($email, $token)
    {
        // Queued via the engine (was a SYNC send inside the request — a slow
        // provider stalled the endpoint). reset_token is redact-listed so it
        // never lands in email_logs.meta.
        try {
            app(\Marvel\Services\EmailService::class)->send('auth.forgot_password', $email, ['reset_token' => $token], [
                'fallback' => fn () => Mail::to($email)->queue(new ForgetPassword($token)),
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * Update user email and send verification link to the user.
     * @param  $request
     * @return string[]
     */

    public function updateEmail($request): array
    {
        $user = $request->user();
        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->save();
        $user->sendEmailVerificationNotification();
        return ['message' => EMAIL_UPDATED_SUCCESSFULLY, 'status' => 'success'];
    }

    public function checkIfApplicationIsValid(): bool
    {
        // Self-hosted PlantAtHome deployment: the upstream Pickbazar license gate
        // (redq.io re-check) was flipping the trust flag off and blocking logins
        // with "The credentials was wrong". Short-circuit it so login never depends
        // on the remote license check. Toggleable via env, defaults to trusted.
        if (filter_var(env('MARVEL_FORCE_TRUST', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $settings = Settings::getData();
        $useMustVerifyLicense = isset($settings->options['app_settings']['trust']) ? $settings->options['app_settings']['trust'] : false;
        return $useMustVerifyLicense;
    }
}
