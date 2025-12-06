# Deployment диаграмма - Развертывание системы

## Описание

Диаграмма развертывания показывает физическую архитектуру развертывания системы Library Stroll.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Internet["Internet"]
        Users[Пользователи]
    end
    
    subgraph LoadBalancer["Load Balancer"]
        LB[Nginx<br/>Load Balancer]
    end
    
    subgraph WebServers["Web Servers Cluster"]
        WS1[Web Server 1<br/>Laravel + PHP-FPM]
        WS2[Web Server 2<br/>Laravel + PHP-FPM]
        WS3[Web Server 3<br/>Laravel + PHP-FPM]
    end
    
    subgraph AppServers["Application Servers"]
        AS1[App Server 1<br/>Queue Workers]
        AS2[App Server 2<br/>Queue Workers]
    end
    
    subgraph Database["Database Cluster"]
        DB1[(MySQL Master<br/>Primary DB)]
        DB2[(MySQL Slave<br/>Read Replica)]
    end
    
    subgraph Cache["Cache Cluster"]
        Redis1[(Redis Master)]
        Redis2[(Redis Replica)]
    end
    
    subgraph Storage["File Storage"]
        FS1[File Server 1<br/>Local Storage]
        FS2[S3 Bucket<br/>Cloud Storage]
    end
    
    subgraph Search["Search Service"]
        ES[Elasticsearch<br/>Search Cluster]
    end
    
    subgraph Queue["Queue Service"]
        MQ[RabbitMQ<br/>Message Queue]
    end
    
    subgraph External["External Services"]
        Pusher[Pusher<br/>Real-time Service]
        SMTP[SMTP Server<br/>Email Service]
    end
    
    Users --> LB
    LB --> WS1
    LB --> WS2
    LB --> WS3
    
    WS1 --> DB1
    WS2 --> DB1
    WS3 --> DB1
    WS1 --> DB2
    WS2 --> DB2
    WS3 --> DB2
    
    WS1 --> Redis1
    WS2 --> Redis1
    WS3 --> Redis1
    WS1 --> Redis2
    WS2 --> Redis2
    WS3 --> Redis2
    
    WS1 --> FS1
    WS2 --> FS1
    WS3 --> FS1
    WS1 --> FS2
    WS2 --> FS2
    WS3 --> FS2
    
    WS1 --> ES
    WS2 --> ES
    WS3 --> ES
    
    AS1 --> MQ
    AS2 --> MQ
    AS1 --> DB1
    AS2 --> DB1
    
    WS1 --> Pusher
    WS2 --> Pusher
    WS3 --> Pusher
    
    AS1 --> SMTP
    AS2 --> SMTP
    
    style Internet fill:#e1f5ff
    style LoadBalancer fill:#fff3e0
    style WebServers fill:#f3e5f5
    style Database fill:#e8f5e9
    style Cache fill:#fce4ec
    style Storage fill:#f1f8e9
```

## Описание узлов развертывания

### Load Balancer
- **Nginx** - балансировщик нагрузки, распределяет запросы между web-серверами

### Web Servers Cluster
- **3 Web Server** - серверы приложений Laravel с PHP-FPM
- Обрабатывают HTTP запросы
- Используют read replicas для чтения данных

### Application Servers
- **2 App Server** - серверы для обработки фоновых задач (queue workers)
- Обрабатывают асинхронные задачи (отправка email, обработка файлов)

### Database Cluster
- **MySQL Master** - основная база данных для записи
- **MySQL Slave** - реплика для чтения (масштабирование чтения)

### Cache Cluster
- **Redis Master** - основной кэш-сервер
- **Redis Replica** - реплика для отказоустойчивости

### File Storage
- **File Server** - локальное хранилище файлов
- **S3 Bucket** - облачное хранилище для резервного копирования

### Search Service
- **Elasticsearch** - кластер поискового движка для полнотекстового поиска

### Queue Service
- **RabbitMQ** - очередь сообщений для асинхронной обработки

### External Services
- **Pusher** - сервис real-time уведомлений
- **SMTP Server** - сервер отправки email

## Масштабирование

- **Горизонтальное масштабирование** - добавление web-серверов
- **Вертикальное масштабирование** - увеличение ресурсов серверов
- **Read Replicas** - масштабирование чтения из БД
- **Cache Replication** - репликация кэша для отказоустойчивости

