<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.pay', 'salaries.view', 'transport.view', 'transport.manage', 'inventory.view', 'inventory.manage', 'purchases.approve', 'assets.dispose',
            'dashboard.view',
            'staff.view', 'staff.create', 'staff.update', 'staff.delete',
            'students.view', 'students.create', 'students.update', 'students.delete',
            'guardians.view', 'guardians.create', 'guardians.update',
            'admissions.view', 'admissions.create', 'admissions.update', 'admissions.approve',
            'academics.view', 'academics.manage',
            'classes.view', 'classes.manage',
            'subjects.view', 'subjects.manage',
            'timetable.view', 'timetable.manage',
            'attendance.view', 'attendance.record',
            'results.view', 'results.enter', 'results.submit', 'results.approve', 'results.publish', 'results.reopen',
            'fees.view', 'fees.manage',
            'invoices.view', 'invoices.create', 'invoices.void',
            'payments.view', 'payments.record', 'payments.reverse',
            'adjustments.view', 'adjustments.request', 'adjustments.approve',
            'expenses.view', 'expenses.create', 'expenses.approve',
            'incomes.view', 'incomes.create',
            'reports.view', 'reports.financial',
            'settings.view', 'settings.manage',
            'users.view', 'users.manage',
            'audit.view',
            'backups.view', 'backups.manage',
            'imports.view', 'imports.manage',
            'communication.view', 'communication.send',
            'documents.view', 'documents.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $roles = [
            'super_admin' => $permissions,
            'headteacher' => [
                'dashboard.view', 'staff.view', 'students.view', 'students.update', 'guardians.view',
                'admissions.view', 'admissions.approve', 'academics.view', 'academics.manage', 'classes.view', 'classes.manage', 'subjects.view', 'subjects.manage',
                'timetable.view', 'timetable.manage', 'attendance.view', 'attendance.record', 'results.view', 'results.enter', 'results.submit', 'results.approve', 'results.publish', 'results.reopen',
                'reports.view', 'settings.view', 'communication.view', 'communication.send', 'documents.view', 'documents.manage', 'imports.view', 'imports.manage', 'transport.view', 'inventory.view',
            ],
            'administrator' => [
                'transport.view', 'transport.manage', 'inventory.view', 'inventory.manage',
                'dashboard.view',
                'students.view', 'students.create', 'students.update', 'students.delete',
                'guardians.view', 'guardians.create', 'guardians.update',
                'admissions.view', 'admissions.create', 'admissions.update', 'admissions.approve',
                'academics.view', 'academics.manage',
                'classes.view', 'classes.manage',
                'subjects.view', 'subjects.manage',
                'timetable.view', 'timetable.manage',
                'attendance.view', 'attendance.record',
                'results.view', 'results.enter', 'results.submit',
                'fees.view', 'invoices.view', 'invoices.create', 'payments.view',
                'reports.view', 'imports.view', 'imports.manage',
                'communication.view', 'communication.send',
                'documents.view', 'documents.manage',
                'settings.view',
            ],
            'accountant' => [
                'payroll.view', 'payroll.manage', 'payroll.pay', 'salaries.view', 'transport.view', 'inventory.view',
                'dashboard.view',
                'students.view', 'guardians.view',
                'fees.view', 'fees.manage',
                'invoices.view', 'invoices.create',
                'payments.view', 'payments.record',
                'adjustments.view', 'adjustments.request',
                'expenses.view', 'expenses.create',
                'incomes.view', 'incomes.create',
                'reports.view', 'reports.financial',
                'documents.view',
            ],
            'teacher' => [
                'dashboard.view',
                'students.view',
                'academics.view', 'classes.view', 'subjects.view', 'timetable.view',
                'attendance.view', 'attendance.record',
                'results.view', 'results.enter', 'results.submit',
                'reports.view',
            ],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
