<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;

class SetupDataCommand extends Command
{
    protected $signature = 'data:setup {--demo-users : Создать тестовые аккаунты (только local/testing)}';

    protected $description = 'Применить миграции и создать справочники без случайных артов и загрузок';

    public function handle(): int
    {
        if ($this->option('demo-users') && ! app()->environment(['local', 'testing'])) {
            $this->error('Тестовые аккаунты доступны только в local/testing.');

            return self::FAILURE;
        }
        foreach ([
            ['migrate', []],
            ['db:seed', ['--class' => DatabaseSeeder::class]],
        ] as [$command, $arguments]) {
            $result = $this->call($command, $arguments);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }
        if ($this->option('demo-users')) {
            $result = $this->call('db:seed', ['--class' => UserSeeder::class]);
            if ($result !== self::SUCCESS) {
                return $result;
            }
            $this->warn('Новые demo-аккаунты используют пароль password; не используйте их в production.');
        }
        $this->info('Готово. Импорт: php artisan artworks:import --source=met --limit=20 --publish');

        return self::SUCCESS;
    }
}
