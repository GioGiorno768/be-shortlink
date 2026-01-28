<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethodTemplate;

class PaymentMethodTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            // Indonesian E-Wallets
            [
                'name' => 'DANA',
                'type' => 'wallet',
                'currency' => 'IDR',
                'input_type' => 'phone',
                'input_label' => 'Nomor HP DANA',
                'icon' => 'dana',
                'fee' => 0,
                'min_amount' => 10000,
                'max_amount' => 10000000,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'OVO',
                'type' => 'wallet',
                'currency' => 'IDR',
                'input_type' => 'phone',
                'input_label' => 'Nomor HP OVO',
                'icon' => 'ovo',
                'fee' => 0,
                'min_amount' => 10000,
                'max_amount' => 10000000,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'GoPay',
                'type' => 'wallet',
                'currency' => 'IDR',
                'input_type' => 'phone',
                'input_label' => 'Nomor HP GoPay',
                'icon' => 'gopay',
                'fee' => 0,
                'min_amount' => 10000,
                'max_amount' => 10000000,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'ShopeePay',
                'type' => 'wallet',
                'currency' => 'IDR',
                'input_type' => 'phone',
                'input_label' => 'Nomor HP ShopeePay',
                'icon' => 'shopeepay',
                'fee' => 0,
                'min_amount' => 10000,
                'max_amount' => 10000000,
                'is_active' => true,
                'sort_order' => 4,
            ],

            // Indonesian Banks
            [
                'name' => 'BCA',
                'type' => 'bank',
                'currency' => 'IDR',
                'input_type' => 'account_number',
                'input_label' => 'Nomor Rekening BCA',
                'icon' => 'bca',
                'fee' => 2500,
                'min_amount' => 50000,
                'max_amount' => 50000000,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'BNI',
                'type' => 'bank',
                'currency' => 'IDR',
                'input_type' => 'account_number',
                'input_label' => 'Nomor Rekening BNI',
                'icon' => 'bni',
                'fee' => 2500,
                'min_amount' => 50000,
                'max_amount' => 50000000,
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'name' => 'BRI',
                'type' => 'bank',
                'currency' => 'IDR',
                'input_type' => 'account_number',
                'input_label' => 'Nomor Rekening BRI',
                'icon' => 'bri',
                'fee' => 2500,
                'min_amount' => 50000,
                'max_amount' => 50000000,
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'Mandiri',
                'type' => 'bank',
                'currency' => 'IDR',
                'input_type' => 'account_number',
                'input_label' => 'Nomor Rekening Mandiri',
                'icon' => 'mandiri',
                'fee' => 2500,
                'min_amount' => 50000,
                'max_amount' => 50000000,
                'is_active' => true,
                'sort_order' => 13,
            ],

            // International
            [
                'name' => 'PayPal',
                'type' => 'wallet',
                'currency' => 'USD',
                'input_type' => 'email',
                'input_label' => 'Email PayPal',
                'icon' => 'paypal',
                'fee' => 0.50,
                'min_amount' => 5,
                'max_amount' => 1000,
                'is_active' => true,
                'sort_order' => 20,
            ],

            // Crypto
            [
                'name' => 'USDT (TRC20)',
                'type' => 'crypto',
                'currency' => 'USD',
                'input_type' => 'crypto_address',
                'input_label' => 'USDT TRC20 Address',
                'icon' => 'usdt',
                'fee' => 1,
                'min_amount' => 10,
                'max_amount' => 0, // No limit
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'name' => 'Bitcoin',
                'type' => 'crypto',
                'currency' => 'USD',
                'input_type' => 'crypto_address',
                'input_label' => 'Bitcoin Address',
                'icon' => 'bitcoin',
                'fee' => 5,
                'min_amount' => 50,
                'max_amount' => 0, // No limit
                'is_active' => true,
                'sort_order' => 31,
            ],
        ];

        foreach ($templates as $template) {
            PaymentMethodTemplate::updateOrCreate(
                ['name' => $template['name'], 'currency' => $template['currency'], 'type' => $template['type']],
                $template
            );
        }
    }
}
