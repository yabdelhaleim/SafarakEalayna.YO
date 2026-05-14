<?php

namespace Database\Factories\Wallet;

use App\Models\Wallet\WalletType;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTypeFactory extends Factory
{
    protected $model = WalletType::class;

    public function definition(): array
    {
        $names = ['فودافون كاش', 'انستاباي', 'اورنج كاش', 'بنك مصر', 'CIB'];
        $name  = $this->faker->unique()->randomElement($names);

        return [
            'name'       => $name,
            'code'       => \Illuminate\Support\Str::slug($name, '_'),
            'is_active'  => true,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
