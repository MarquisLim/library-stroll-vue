# Диаграмма развертывания - Waterfall модель

## Описание

Диаграмма показывает физическую архитектуру развертывания системы Library Stroll в каскадной модели. Все компоненты развертываются одновременно после завершения тестирования.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Internet["Internet"]
        Users[Пользователи]
    end
    
    subgraph DMZ["DMZ Zone"]
        LoadBalancer[Load Balancer<br/>Nginx]
    end
    
    subgraph WebServers["Web Servers Cluster"]
        WebServer1[Web Server 1<br/>Apache/Nginx + PHP-FPM]
        WebServer2[Web Server 2<br/>Apache/Nginx + PHP-FPM]
    end
    
    subgraph AppServers["Application Servers"]
        AppServer1[Laravel App 1<br/>PHP 8.2]
        AppServer2[Laravel App 2<br/>PHP 8.2]
    end
    
    subgraph DatabaseCluster["Database Cluster"]
        DBMaster[(MySQL Master<br/>Primary)]
        DBSlave1[(MySQL Slave 1<br/>Read Replica)]
        DBSlave2[(MySQL Slave 2<br/>Read Replica)]
    end
    
    subgraph Storage["Storage Servers"]
        FileStorage[(File Storage<br/>NFS/S3)]
        MediaStorage[(Media Storage<br/>Local/S3)]
    end
    
    subgraph CacheLayer["Cache Layer"]
        Redis1[(Redis Master)]
        Redis2[(Redis Replica)]
    end
    
    subgraph QueueServers["Queue Servers"]
        QueueWorker1[Queue Worker 1]
        QueueWorker2[Queue Worker 2]
    end
    
    subgraph ExternalServices["External Services"]
        PusherService[Pusher Cloud<br/>Real-time]
        CDN[CDN<br/>Static Assets]
    end
    
    Users --> LoadBalancer
    LoadBalancer --> WebServer1
    LoadBalancer --> WebServer2
    
    WebServer1 --> AppServer1
    WebServer2 --> AppServer2
    
    AppServer1 --> DBMaster
    AppServer2 --> DBMaster
    AppServer1 --> DBSlave1
    AppServer2 --> DBSlave2
    
    DBMaster --> DBSlave1
    DBMaster --> DBSlave2
    
    AppServer1 --> Redis1
    AppServer2 --> Redis1
    Redis1 --> Redis2
    
    AppServer1 --> FileStorage
    AppServer2 --> FileStorage
    AppServer1 --> MediaStorage
    AppServer2 --> MediaStorage
    
    QueueWorker1 --> DBMaster
    QueueWorker2 --> DBMaster
    QueueWorker1 --> Redis1
    QueueWorker2 --> Redis1
    
    AppServer1 --> PusherService
    AppServer2 --> PusherService
    
    WebServer1 --> CDN
    WebServer2 --> CDN
    
    style Internet fill:#e1f5ff
    style DMZ fill:#fff3e0
    style WebServers fill:#f3e5f5
    style AppServers fill:#e8f5e9
    style DatabaseCluster fill:#fce4ec
    style Storage fill:#f1f8e9
    style CacheLayer fill:#fff9c4
    style QueueServers fill:#e0f2f1
    style ExternalServices fill:#f3e5f5
```

## Описание узлов развертывания

### DMZ Zone
- **Load Balancer (Nginx)** — распределение нагрузки между веб-серверами

### Web Servers Cluster
- **Web Server 1, 2** — веб-серверы с PHP-FPM для обработки запросов

### Application Servers
- **Laravel App 1, 2** — экземпляры Laravel приложения

### Database Cluster
- **MySQL Master** — основная БД для записи
- **MySQL Slaves** — реплики для чтения (масштабирование)

### Storage Servers
- **File Storage** — хранилище файлов (NFS или S3)
- **Media Storage** — хранилище медиа-контента

### Cache Layer
- **Redis Master/Replica** — кэширование и сессии

### Queue Servers
- **Queue Workers** — обработка фоновых задач

### External Services
- **Pusher Cloud** — real-time коммуникация
- **CDN** — доставка статических ресурсов

## Особенности развертывания в Waterfall

- **Полное развертывание** — все компоненты развертываются одновременно
- **Масштабируемость** — горизонтальное масштабирование веб-серверов и БД
- **Высокая доступность** — репликация БД и Redis
- **Централизованное хранилище** — общее хранилище файлов для всех серверов

