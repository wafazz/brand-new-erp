<?php

declare(strict_types=1);

namespace App\Domain\Finance;

enum AccountCode: string
{
    case Bank = '1000';
    case AccountsReceivable = '1100';
    case Inventory = '1200';
    case AccountsPayable = '2000';
    case TaxPayable = '2100';
    case CommissionPayable = '2200';
    case Sales = '4000';
    case CostOfGoodsSold = '5000';
    case CommissionExpense = '5100';
    case OperatingExpense = '5200';

    public function type(): string
    {
        return match ($this) {
            self::Bank, self::AccountsReceivable, self::Inventory => 'asset',
            self::AccountsPayable, self::TaxPayable, self::CommissionPayable => 'liability',
            self::Sales => 'income',
            self::CostOfGoodsSold, self::CommissionExpense, self::OperatingExpense => 'expense',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::AccountsReceivable => 'Accounts receivable',
            self::Inventory => 'Inventory',
            self::AccountsPayable => 'Accounts payable',
            self::TaxPayable => 'Tax payable',
            self::CommissionPayable => 'Commission payable',
            self::Sales => 'Sales',
            self::CostOfGoodsSold => 'Cost of goods sold',
            self::CommissionExpense => 'Commission expense',
            self::OperatingExpense => 'Operating expense',
        };
    }
}
