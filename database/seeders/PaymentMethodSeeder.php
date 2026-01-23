<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            // Deposit Methods
            [
                'name' => 'USDT (TRC20)',
                'description' => 'Dépôt via USDT sur le réseau TRON',
                'type' => 'deposit',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 5,
                'max_amount' => 10000,
                'fee_percentage' => 0,
                'crypto_address' => 'TSf7x19gfn72Jk4Ah4RWVYuGxvYt5HMWqc',
                'crypto_network' => 'TRON (TRC20)',
            ],
            [
                'name' => 'USDT (ERC20)',
                'description' => 'Dépôt via USDT sur le réseau Ethereum',
                'type' => 'deposit',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 5,
                'max_amount' => 10000,
                'fee_percentage' => 0,
                'crypto_address' => '0xAbc123Def456Ghi789Jkl0Mno1Pqr2Stu3Vwx4Yz5',
                'crypto_network' => 'Ethereum (ERC20)',
            ],
            [
                'name' => 'Bitcoin (BTC)',
                'description' => 'Dépôt via Bitcoin',
                'type' => 'deposit',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 20,
                'max_amount' => 20000,
                'fee_percentage' => 1,
                'crypto_address' => 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
                'crypto_network' => 'Bitcoin',
            ],
            [
                'name' => 'Ethereum (ETH)',
                'description' => 'Dépôt via Ethereum',
                'type' => 'deposit',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 10,
                'max_amount' => 15000,
                'fee_percentage' => 0.5,
                'crypto_address' => '0x742d35Cc6634C0532925a3b844Bc454e4438f444',
                'crypto_network' => 'Ethereum',
            ],
            [
                'name' => 'Cash via Agent',
                'description' => 'Dépôt en espèces via un agent de recharge',
                'type' => 'deposit',
                'method_key' => 'cash',
                'is_active' => true,
                'min_amount' => 1,
                'max_amount' => 5000,
                'fee_fixed' => 0.5,
            ],
            // Withdrawal Methods
            [
                'name' => 'USDT (TRC20)',
                'description' => 'Retrait via USDT sur le réseau TRON',
                'type' => 'withdrawal',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 10,
                'max_amount' => 50000,
                'fee_percentage' => 0.5,
                'fee_fixed' => 1,
                'crypto_network' => 'TRON (TRC20)',
            ],
            [
                'name' => 'USDT (ERC20)',
                'description' => 'Retrait via USDT sur le réseau Ethereum',
                'type' => 'withdrawal',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 10,
                'max_amount' => 50000,
                'fee_percentage' => 0.8,
                'fee_fixed' => 2,
                'crypto_network' => 'Ethereum (ERC20)',
            ],
            [
                'name' => 'Bitcoin (BTC)',
                'description' => 'Retrait via Bitcoin',
                'type' => 'withdrawal',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 50,
                'max_amount' => 100000,
                'fee_percentage' => 1.5,
                'fee_fixed' => 5,
                'crypto_network' => 'Bitcoin',
            ],
            [
                'name' => 'Ethereum (ETH)',
                'description' => 'Retrait via Ethereum',
                'type' => 'withdrawal',
                'method_key' => 'crypto',
                'is_active' => true,
                'min_amount' => 30,
                'max_amount' => 80000,
                'fee_percentage' => 1.2,
                'fee_fixed' => 3,
                'crypto_network' => 'Ethereum',
            ],
            [
                'name' => 'Virement Bancaire',
                'description' => 'Retrait par virement bancaire',
                'type' => 'withdrawal',
                'method_key' => 'bank_transfer',
                'is_active' => true,
                'min_amount' => 20,
                'max_amount' => 25000,
                'fee_fixed' => 2,
                'bank_name' => 'Any Bank',
            ],
            [
                'name' => 'MTN Mobile Money',
                'description' => 'Retrait via MTN Mobile Money',
                'type' => 'withdrawal',
                'method_key' => 'mobile_money',
                'is_active' => true,
                'min_amount' => 5,
                'max_amount' => 1000,
                'fee_percentage' => 1,
                'mobile_money_provider' => 'MTN',
            ],
            [
                'name' => 'Orange Money',
                'description' => 'Retrait via Orange Money',
                'type' => 'withdrawal',
                'method_key' => 'mobile_money',
                'is_active' => true,
                'min_amount' => 5,
                'max_amount' => 1000,
                'fee_percentage' => 1,
                'mobile_money_provider' => 'Orange',
            ],
            [
                'name' => 'Moov Money',
                'description' => 'Retrait via Moov Money',
                'type' => 'withdrawal',
                'method_key' => 'mobile_money',
                'is_active' => true,
                'min_amount' => 5,
                'max_amount' => 1000,
                'fee_percentage' => 1,
                'mobile_money_provider' => 'Moov',
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                [
                    'name' => $method['name'],
                    'type' => $method['type'],
                ],
                $method
            );
        }
    }
}


