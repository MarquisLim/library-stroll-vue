<?php

namespace Database\Seeders;

use App\Models\Models\Complaint\ComplaintType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ComplaintTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['slug'=>'phishing',            'name'=>'Реклама или фишинговые ссылки'],
            ['slug'=>'account_trading',     'name'=>'Продажа или обмен учётной записи'],
            ['slug'=>'privacy_leak',        'name'=>'Разглашение закрытой информации'],
            ['slug'=>'sensitive_info',      'name'=>'Неоднозначный контент или личная информация'],
            ['slug'=>'harassment',          'name'=>'Вербальное насилие и угрозы'],
            ['slug'=>'copyright',           'name'=>'Нарушение авторских прав'],
            ['slug'=>'impersonation',       'name'=>'Выдача себя за другое лицо'],
            ['slug'=>'community_rules',     'name'=>'Нарушение правил сообщества'],
            ['slug'=>'sexual_violence',     'name'=>'Сексуальное насилие и эксплуатация детей'],
        ];

        foreach ($types as $t) {
            ComplaintType::updateOrCreate(['slug'=>$t['slug']], $t);
        }
    }
}
