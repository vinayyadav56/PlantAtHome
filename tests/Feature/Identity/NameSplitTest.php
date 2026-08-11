<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * users.name stays the authoritative display string; first_name/last_name are
 * the structured pair. The User::saving observer keeps the two in sync in BOTH
 * directions — it is the single choke point covering every creation site
 * (register, social login, OTP login, vendor-owner creation, DP creation).
 * Profile::saving derives contact_clean (last-10-digits) — the deterministic
 * key OTP login matches on across legacy phone formats.
 */
final class NameSplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('first_name', 120)->nullable();
            $t->string('last_name', 120)->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('contact')->nullable();
            $t->string('contact_clean', 10)->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->timestamps();
        });
    }

    public function test_create_with_name_only_splits_on_first_space(): void
    {
        $u = User::create(['name' => 'Ananya Sharma Rao', 'email' => 'a@x.in']);

        $this->assertSame('Ananya', $u->first_name);
        $this->assertSame('Sharma Rao', $u->last_name);
        $this->assertSame('Ananya Sharma Rao', $u->name);
    }

    public function test_single_word_name_leaves_last_name_null(): void
    {
        $u = User::create(['name' => 'Ananya', 'email' => 'b@x.in']);

        $this->assertSame('Ananya', $u->first_name);
        $this->assertNull($u->last_name);
    }

    public function test_create_with_first_last_derives_name(): void
    {
        $u = User::create(['first_name' => 'Vinay', 'last_name' => 'Yadav', 'email' => 'c@x.in']);

        $this->assertSame('Vinay Yadav', $u->name);
    }

    public function test_create_with_name_and_null_first_still_splits(): void
    {
        // register() passes first_name => null for old clients — the null
        // attribute must not suppress the split.
        $u = User::create(['name' => 'Old Client', 'first_name' => null, 'last_name' => null, 'email' => 'd@x.in']);

        $this->assertSame('Old', $u->first_name);
        $this->assertSame('Client', $u->last_name);
    }

    public function test_updating_first_last_resyncs_name(): void
    {
        $u = User::create(['name' => 'Ananya Sharma', 'email' => 'e@x.in']);
        $u->first_name = 'Priya';
        $u->last_name = 'Mehta';
        $u->save();

        $this->assertSame('Priya Mehta', $u->fresh()->name);
    }

    public function test_updating_name_resplits(): void
    {
        $u = User::create(['name' => 'Ananya Sharma', 'email' => 'f@x.in']);
        $u->name = 'Kiran Rao';
        $u->save();

        $this->assertSame('Kiran', $u->fresh()->first_name);
        $this->assertSame('Rao', $u->fresh()->last_name);
    }

    public function test_profile_contact_clean_normalizes_all_legacy_formats(): void
    {
        foreach (['9876543210', '+919876543210', '919876543210', '098765 43210'] as $i => $format) {
            $p = Profile::create(['contact' => $format, 'customer_id' => $i + 1]);
            $this->assertSame('9876543210', $p->contact_clean, "format: {$format}");
        }
    }

    public function test_bare_ten_digit_contact_is_stored_e164(): void
    {
        $p = Profile::create(['contact' => '9876543210', 'customer_id' => 99]);

        $this->assertSame('+919876543210', $p->contact);
        $this->assertSame('9876543210', $p->contact_clean);
    }
}
