<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Manager = 'manager';
    case WarehouseOperator = 'warehouse_operator';
    case Administrator = 'administrator';
    case SuperAdministrator = 'super_administrator';
}
