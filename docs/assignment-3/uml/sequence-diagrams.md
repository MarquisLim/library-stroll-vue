# UML Диаграммы последовательности

## Описание

Диаграммы последовательности, полученные трансформацией последовательностей процессов из DFD.

## Диаграмма последовательности: Загрузка работы

```mermaid
sequenceDiagram
    actor User as Пользователь
    participant AS as ArtworkService
    participant FV as FileValidator
    participant IP as ImageProcessor
    participant SS as StorageService
    participant AR as ArtworkRepository
    participant DB as Database
    participant FS as FileStorage
    
    User->>AS: uploadArtwork(file, metadata)
    AS->>FV: validate(file)
    FV-->>AS: ValidationResult
    
    alt Файл валиден
        AS->>IP: process(image)
        IP->>IP: resize(image)
        IP->>IP: createThumbnail(image)
        IP-->>AS: ProcessedImage
        
        AS->>SS: save(file, path)
        SS->>FS: save(file, path)
        FS-->>SS: filePath
        SS-->>AS: filePath
        
        AS->>AR: create(artworkData)
        AR->>DB: save(artwork)
        DB-->>AR: artwork
        AR-->>AS: Artwork
        
        AS-->>User: Artwork (успех)
    else Файл не валиден
        AS-->>User: ValidationError
    end
```

## Диаграмма последовательности: Получение работы

```mermaid
sequenceDiagram
    actor User as Пользователь
    participant AS as ArtworkService
    participant AR as ArtworkRepository
    participant DB as Database
    participant SS as StorageService
    participant FS as FileStorage
    
    User->>AS: getArtwork(id)
    AS->>AR: findById(id)
    AR->>DB: find(Artwork, id)
    DB-->>AR: artwork
    AR-->>AS: Artwork
    
    AS->>SS: get(filePath)
    SS->>FS: get(filePath)
    FS-->>SS: File
    SS-->>AS: File
    
    AS-->>User: Artwork + File
```

## Диаграмма последовательности: Обновление работы

```mermaid
sequenceDiagram
    actor User as Пользователь
    participant AS as ArtworkService
    participant AR as ArtworkRepository
    participant DB as Database
    
    User->>AS: updateArtwork(id, data)
    AS->>AR: update(id, data)
    AR->>DB: update(Artwork, id, data)
    DB-->>AR: updatedArtwork
    AR-->>AS: Artwork
    AS-->>User: Artwork (обновлен)
```

## Диаграмма последовательности: Удаление работы

```mermaid
sequenceDiagram
    actor User as Пользователь
    participant AS as ArtworkService
    participant AR as ArtworkRepository
    participant DB as Database
    participant SS as StorageService
    participant FS as FileStorage
    
    User->>AS: deleteArtwork(id)
    AS->>AR: findById(id)
    AR->>DB: find(Artwork, id)
    DB-->>AR: artwork
    AR-->>AS: Artwork
    
    AS->>SS: delete(filePath)
    SS->>FS: delete(filePath)
    FS-->>SS: success
    
    AS->>AR: delete(id)
    AR->>DB: delete(Artwork, id)
    DB-->>AR: success
    AR-->>AS: success
    
    AS-->>User: success
```

## Соответствие DFD процессам

- **Загрузка работы** соответствует последовательности: 2.1 → 2.2 → 2.3 → 2.4
- **Получение работы** соответствует процессу: 2.5
- **Обновление работы** соответствует процессу: 2.6
- **Удаление работы** соответствует процессу: 2.7

