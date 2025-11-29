<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Group;
use App\Models\Member;
use App\Models\Wallet;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test groups first if they don't exist
        $group1 = Group::firstOrCreate(
            ['name' => 'Alpha Group'],
            ['description' => 'First test group', 'created_by' => 'System']
        );

        $group2 = Group::firstOrCreate(
            ['name' => 'Beta Group'],
            ['description' => 'Second test group', 'created_by' => 'System']
        );

        // 1. Super Admin - Can manage all groups
        User::firstOrCreate(
            ['email' => 'super@maisha.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('super123'),
                'role' => 'super_admin',
                'group_id' => null,
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@maisha.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'group_id' => null,
            ]
        );

        // 2. Group Admin for Alpha Group
        User::firstOrCreate(
            ['email' => 'alpha@maisha.com'],
            [
                'name' => 'Alpha Admin',
                'password' => Hash::make('alpha123'),
                'role' => 'group_admin',
                'group_id' => $group1->id,
            ]
        );

        // 3. Group User for Alpha Group
        User::firstOrCreate(
            ['email' => 'user1@maisha.com'],
            [
                'name' => 'Alpha User',
                'password' => Hash::make('user123'),
                'role' => 'group_user',
                'group_id' => $group1->id,
            ]
        );

        // 4. Group Admin for Beta Group
        User::firstOrCreate(
            ['email' => 'beta@maisha.com'],
            [
                'name' => 'Beta Admin',
                'password' => Hash::make('beta123'),
                'role' => 'group_admin',
                'group_id' => $group2->id,
            ]
        );

        // 5. Group User for Beta Group
        User::firstOrCreate(
            ['email' => 'user2@maisha.com'],
            [
                'name' => 'Beta User',
                'password' => Hash::make('user123'),
                'role' => 'group_user',
                'group_id' => $group2->id,
            ]
        );

        // Create Members with User accounts
        // Member 1 - Alpha Group
        $member1 = Member::firstOrCreate(
            ['national_id' => 'MEM001'],
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'birth_date' => '1990-05-15',
                'gender' => 'MALE',
                'is_active' => true,
                'phone' => '250788123456',
                'group_id' => $group1->id,
            ]
        );

        // Create user account for Member 1
        $memberUser1 = User::firstOrCreate(
            ['email' => 'john.doe@member.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('member123'),
                'role' => 'member',
                'group_id' => $group1->id,
            ]
        );

        // Create wallet for Member 1
        Wallet::firstOrCreate(
            ['member_id' => $member1->id],
            [
                'balance' => 5000,
                'currency' => 'RWF',
                'is_active' => true,
            ]
        );

        // Member 2 - Alpha Group
        $member2 = Member::firstOrCreate(
            ['national_id' => 'MEM002'],
            [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'birth_date' => '1992-08-22',
                'gender' => 'FEMALE',
                'is_active' => true,
                'phone' => '250788234567',
                'group_id' => $group1->id,
            ]
        );

        // Create user account for Member 2
        $memberUser2 = User::firstOrCreate(
            ['email' => 'jane.smith@member.com'],
            [
                'name' => 'Jane Smith',
                'password' => Hash::make('member123'),
                'role' => 'member',
                'group_id' => $group1->id,
            ]
        );

        // Create wallet for Member 2
        Wallet::firstOrCreate(
            ['member_id' => $member2->id],
            [
                'balance' => 7500,
                'currency' => 'RWF',
                'is_active' => true,
            ]
        );

        // Member 3 - Beta Group
        $member3 = Member::firstOrCreate(
            ['national_id' => 'MEM003'],
            [
                'first_name' => 'Bob',
                'last_name' => 'Johnson',
                'birth_date' => '1988-03-10',
                'gender' => 'MALE',
                'is_active' => true,
                'phone' => '250788345678',
                'group_id' => $group2->id,
            ]
        );

        // Create user account for Member 3
        $memberUser3 = User::firstOrCreate(
            ['email' => 'bob.johnson@member.com'],
            [
                'name' => 'Bob Johnson',
                'password' => Hash::make('member123'),
                'role' => 'member',
                'group_id' => $group2->id,
            ]
        );        // Create wallet for Member 3
        Wallet::firstOrCreate(
            ['member_id' => $member3->id],
            [
                'balance' => 3000,
                'currency' => 'RWF',
                'is_active' => true,
            ]
        );
    }
}
