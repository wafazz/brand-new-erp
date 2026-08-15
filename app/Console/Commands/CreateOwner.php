<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Access\RoleProvisioner;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class CreateOwner extends Command
{
    protected $signature = 'erp:create-owner
        {--company= : Name of the company to create or reuse}
        {--name= : The owner\'s name}
        {--email= : The owner\'s email}';

    protected $description = 'Create a company and its first owner account. The password is always typed in, never passed as an argument.';

    public function handle(CompanyContext $context, RoleProvisioner $provisioner, PermissionRegistrar $registrar): int
    {
        $companyName = (string) ($this->option('company') ?? $this->ask('Company name'));
        $name = (string) ($this->option('name') ?? $this->ask('Owner name'));
        $email = (string) ($this->option('email') ?? $this->ask('Owner email'));

        $password = (string) $this->secret('Password (at least 12 characters)');

        $validator = Validator::make(
            compact('companyName', 'name', 'email', 'password'),
            [
                'companyName' => ['required', 'string', 'max:160'],
                'name' => ['required', 'string', 'max:160'],
                'email' => ['required', 'email', 'max:160', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        $company = Company::query()->firstWhere('name', $companyName)
            ?? Company::create(['name' => $companyName, 'slug' => str()->slug($companyName)]);

        $provisioner->provision($company);

        $user = DB::transaction(function () use ($company, $name, $email, $password, $context, $registrar): User {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);

            $context->runAs($company->getKey(), function () use ($company, $user, $registrar): void {
                $registrar->setPermissionsTeamId($company->getKey());

                if (! Branch::query()->where('is_default', true)->exists()) {
                    Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);
                }

                if (! Warehouse::query()->where('is_default', true)->exists()) {
                    Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_default' => true]);
                }

                CompanyUser::create([
                    'user_id' => $user->getKey(),
                    'branch_id' => Branch::query()->where('is_default', true)->value('id'),
                    'role' => 'owner',
                    'is_active' => true,
                ]);

                $user->assignRole('owner');
            });

            $user->forceFill(['active_company_id' => $company->getKey()])->save();

            return $user;
        });

        $this->newLine();
        $this->info("Company [{$company->name}] and owner [{$user->email}] are ready.");
        $this->line('Sign in at '.config('app.url').'/login');

        return self::SUCCESS;
    }
}
