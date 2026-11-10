<?php

$source = fn ($url, $search, $detail, $hosts, $types = ['image'], $enabled = true, $template = null, $key = null) => [
    'enabled' => $enabled, 'base_url' => $url, 'search_endpoint' => $search, 'detail_endpoint' => $detail,
    'media_url_template' => $template, 'allowed_media_hosts' => $hosts, 'api_key' => $key,
    'request_timeout' => 45, 'connect_timeout' => 10, 'rate_limit' => 1, 'retry_attempts' => 2,
    'retry_delay' => 1000, 'max_pages' => 3, 'weight' => 1, 'supported_media_types' => $types,
];

return [
    'max_creator_share' => .25,
    'max_source_share' => .65,
    'root' => storage_path('app/demo-import'),
    'user_agent' => env('DEMO_CONTENT_USER_AGENT', 'LibraryStrollDemo/1.0 (local research catalog; contact: https://github.com/MarquisLim/library-stroll-vue)'),
    'max_image_bytes' => 15 * 1024 * 1024, 'max_video_bytes' => 100 * 1024 * 1024,
    'max_pixels' => 40000000, 'max_video_seconds' => 180, 'failure_threshold' => 3,
    'avatar_script' => base_path('scripts/demo-avatars.mjs'),
    'nasa_license_url' => 'https://www.nasa.gov/nasa-brand-center/images-and-media/',
    'sources' => [
        'met' => $source('https://collectionapi.metmuseum.org', '/public/collection/v1.1/search', '/public/collection/v1/objects/{id}', ['images.metmuseum.org', 'collectionapi.metmuseum.org']),
        'artic' => $source('https://api.artic.edu/api/v1', '/artworks/search', '/artworks/{id}', ['www.artic.edu'], template: 'https://www.artic.edu/iiif/2/{image_id}/full/843,/0/default.jpg'),
        'commons' => $source('https://commons.wikimedia.org', '/w/api.php', '/w/api.php', ['upload.wikimedia.org'], ['image', 'video']),
        'nasa' => $source('https://images-api.nasa.gov', '/search', '/asset/{id}', ['images-assets.nasa.gov'], ['image', 'video']),
        'openverse' => [...$source('https://api.openverse.org/v1', '/images/', '/images/{id}/', [], enabled: (bool) env('OPENVERSE_ENABLED', false)),
            'client_id' => env('OPENVERSE_CLIENT_ID'), 'client_secret' => env('OPENVERSE_CLIENT_SECRET'), 'token_endpoint' => '/auth_tokens/token/'],
        'smithsonian' => $source('https://api.si.edu/openaccess/api/v1.0', '/search', '/content/{id}', ['ids.si.edu', 'nmaahc.si.edu'], enabled: (bool) env('SMITHSONIAN_ENABLED', false), key: env('SMITHSONIAN_API_KEY')),
    ],
    'presets' => ['balanced' => [
        'nature' => ['label' => 'Природа и пейзажи', 'quota' => 60, 'queries' => ['landscape', 'mountains', 'forest'], 'sources' => ['met', 'artic', 'commons']],
        'animals' => ['label' => 'Животные и птицы', 'quota' => 55, 'queries' => ['birds', 'animals', 'horses'], 'sources' => ['met', 'commons', 'artic']],
        'city' => ['label' => 'Город и архитектура', 'quota' => 50, 'queries' => ['architecture', 'city', 'buildings'], 'sources' => ['commons', 'artic', 'met']],
        'people' => ['label' => 'Портреты и люди', 'quota' => 45, 'queries' => ['portrait', 'people'], 'sources' => ['met', 'artic']],
        'abstract' => ['label' => 'Абстракция и геометрия', 'quota' => 40, 'queries' => ['abstract', 'geometric'], 'sources' => ['artic', 'commons']],
        'graphics' => ['label' => 'Иллюстрация и графика', 'quota' => 45, 'queries' => ['drawing', 'engraving', 'illustration'], 'sources' => ['met', 'artic']],
        'history' => ['label' => 'История и архивы', 'quota' => 35, 'queries' => ['historical', 'archive', 'antiquities'], 'sources' => ['met', 'commons', 'smithsonian']],
        'science' => ['label' => 'Наука и технологии', 'quota' => 35, 'queries' => ['science', 'technology', 'laboratory'], 'sources' => ['nasa', 'commons', 'smithsonian']],
        'space' => ['label' => 'Космос', 'quota' => 30, 'queries' => ['moon', 'galaxy', 'space'], 'sources' => ['nasa', 'commons']],
        'transport' => ['label' => 'Транспорт', 'quota' => 30, 'queries' => ['train', 'aircraft', 'transport'], 'sources' => ['commons', 'nasa']],
        'sea' => ['label' => 'Морская тематика', 'quota' => 30, 'queries' => ['ships', 'ocean', 'seascape'], 'sources' => ['met', 'commons', 'artic']],
        'food' => ['label' => 'Еда и натюрморты', 'quota' => 30, 'queries' => ['still life', 'fruit', 'food'], 'sources' => ['met', 'artic']],
        'plants' => ['label' => 'Растения и цветы', 'quota' => 45, 'queries' => ['flowers', 'botanical', 'plants'], 'sources' => ['met', 'commons', 'artic']],
        'culture' => ['label' => 'Культура и мода', 'quota' => 25, 'queries' => ['costume', 'fashion', 'festival'], 'sources' => ['met', 'artic', 'smithsonian']],
        'fantasy' => ['label' => 'Фантастика и фэнтези', 'quota' => 25, 'queries' => ['mythology', 'dragon', 'fantasy'], 'sources' => ['met', 'commons', 'artic']],
        'sport' => ['label' => 'Спорт и движение', 'quota' => 20, 'queries' => ['sport', 'athletics', 'dance'], 'sources' => ['commons', 'artic']],
    ]],
];
