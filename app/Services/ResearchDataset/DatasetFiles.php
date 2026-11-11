<?php

namespace App\Services\ResearchDataset;

use Illuminate\Support\Facades\File;
use RuntimeException;

class DatasetFiles
{
    public function directory(string $name): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $name) || str_contains($name, '..')) {
            throw new RuntimeException('Invalid dataset name');
        }

        return config('research_dataset.root').'/'.$name;
    }

    public function version(string $name, string $version): string
    {
        if (! preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version)) {
            throw new RuntimeException('Invalid version; use 1.0 or 1.0.1');
        }

        return $this->directory($name).'/versions/'.$version;
    }

    public function locked(string $name, callable $callback): mixed
    {
        $root = $this->directory($name);
        File::ensureDirectoryExists($root);
        $lock = fopen($root.'/.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another dataset operation is running');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function canonical(mixed $data): mixed
    {
        if (is_array($data)) {
            if (! array_is_list($data)) {
                ksort($data, SORT_STRING);
            }
            foreach ($data as &$value) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }

    public function json(mixed $data): string
    {
        return json_encode($this->canonical($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function hash(mixed $data): string
    {
        return hash('sha256', $this->json($data));
    }

    public function write(string $path, string $bytes): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            if (File::put($temporary, $bytes) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Cannot save dataset artifact');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(string $path): string
    {
        if (! is_file($path) || filesize($path) > config('research_dataset.max_file_bytes')) {
            throw new RuntimeException('Missing/oversized dataset artifact: '.basename($path));
        }

        return File::get($path);
    }

    public function object(string $path): array
    {
        return json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function lines(string $path): array
    {
        $text = trim($this->read($path));

        return $text === '' ? [] : array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", $text));
    }

    public function writeLines(string $path, array $rows): void
    {
        $this->write($path, implode('', array_map(fn ($row) => $this->json($row)."\n", $rows)));
    }

    public function csv(string $path, array $header, array $rows): void
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fputcsv($stream, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($key) => $row[$key] ?? '', $header), escape: '');
            }
            rewind($stream);
            $this->write($path, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function csvRows(string $path): array
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, $this->read($path));
            rewind($stream);
            $header = fgetcsv($stream, escape: '');
            if (! $header || count($header) !== count(array_unique($header))) {
                throw new RuntimeException('Invalid CSV header');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header)) {
                    throw new RuntimeException('Malformed CSV row');
                }
                $rows[] = array_combine($header, $row);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function report(string $name, string $kind, array $data): string
    {
        $path = $this->directory($name).'/reports/'.$kind.'-'.now()->format('Ymd-His-u').'.json';
        $this->write($path, $this->json($data)."\n");

        return $path;
    }

    public function publicLink(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (preg_match('/(?:^|&)(?:api[_-]?key|access[_-]?token|token|password|client[_-]?secret|secret|x-amz-signature|x-amz-credential|x-amz-security-token)=/i', urldecode($p['query'] ?? ''))) {
            return false;
        }

        return ($p['scheme'] ?? '') === 'https' && $host !== '' && ! isset($p['user']) && ! isset($p['pass'])
            && (! isset($p['port']) || $p['port'] === 443) && $host !== 'localhost' && ! str_ends_with($host, '.local') && ! str_ends_with($host, '.internal')
            && (! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }
}
