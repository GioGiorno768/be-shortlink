<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Referral Commission Percentage
    |--------------------------------------------------------------------------
    |
    | Nilai ini menentukan berapa persen bonus yang akan diterima oleh
    | pengundang (referrer) dari total penarikan dana (withdrawal) yang
    | dilakukan oleh pengguna yang diundang.
    |
    | Contoh: 10 berarti 10%
    |
    */

    'commission_percentage' => env('REFERRAL_COMMISSION_PERCENTAGE', 10),

];