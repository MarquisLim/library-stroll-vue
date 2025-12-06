"""
Реализация ArtworkService с применением GRASP шаблонов
"""

from typing import Optional, List
from dataclasses import dataclass


@dataclass
class Artwork:
    """Модель работы"""
    id: int
    user_id: int
    title: str
    description: Optional[str]
    file_path: str
    is_published: bool


class ArtworkRepository:
    """
    GRASP: Creator
    Ответственность: Создание и управление объектами Artwork
    """
    
    def __init__(self, db):
        self.db = db
    
    def create(self, data: dict) -> Artwork:
        """Создает новый объект Artwork"""
        artwork = Artwork(
            id=None,  # Будет присвоен БД
            user_id=data['user_id'],
            title=data['title'],
            description=data.get('description'),
            file_path=data['file_path'],
            is_published=data.get('is_published', False)
        )
        artwork.id = self.db.save(artwork)
        return artwork
    
    def find_by_id(self, artwork_id: int) -> Optional[Artwork]:
        """Находит работу по ID"""
        return self.db.find(Artwork, artwork_id)
    
    def update(self, artwork_id: int, data: dict) -> Artwork:
        """Обновляет работу"""
        artwork = self.find_by_id(artwork_id)
        if artwork:
            for key, value in data.items():
                setattr(artwork, key, value)
            self.db.save(artwork)
        return artwork
    
    def delete(self, artwork_id: int) -> None:
        """Удаляет работу"""
        self.db.delete(Artwork, artwork_id)


class ArtworkValidator:
    """
    GRASP: Information Expert
    Ответственность: Валидация данных работ
    """
    
    MAX_FILE_SIZE = 10 * 1024 * 1024  # 10 MB
    ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4']
    MAX_TITLE_LENGTH = 255
    
    def validate_file(self, file) -> dict:
        """Валидирует файл"""
        errors = []
        if file.size > self.MAX_FILE_SIZE:
            errors.append("File size exceeds maximum allowed size")
        if file.type not in self.ALLOWED_TYPES:
            errors.append("File type not allowed")
        return {'valid': len(errors) == 0, 'errors': errors}
    
    def validate_metadata(self, metadata: dict) -> dict:
        """Валидирует метаданные"""
        errors = []
        if not metadata.get('title'):
            errors.append("Title is required")
        if len(metadata.get('title', '')) > self.MAX_TITLE_LENGTH:
            errors.append("Title exceeds maximum length")
        return {'valid': len(errors) == 0, 'errors': errors}
    
    def validate(self, file, metadata: dict) -> dict:
        """Полная валидация"""
        file_validation = self.validate_file(file)
        metadata_validation = self.validate_metadata(metadata)
        
        all_errors = file_validation['errors'] + metadata_validation['errors']
        return {'valid': len(all_errors) == 0, 'errors': all_errors}


class FileStorageService:
    """
    GRASP: Low Coupling
    Ответственность: Изолированная работа с файловым хранилищем
    """
    
    def __init__(self, storage_path: str):
        self.storage_path = storage_path
    
    def upload_file(self, file) -> str:
        """Загружает файл и возвращает путь"""
        # Реализация загрузки файла
        file_path = f"{self.storage_path}/{file.name}"
        # Сохранение файла
        return file_path
    
    def delete_file(self, file_path: str) -> None:
        """Удаляет файл"""
        # Реализация удаления файла
        pass


class TagService:
    """
    GRASP: High Cohesion
    Ответственность: Все операции с тегами в одном месте
    """
    
    def __init__(self, tag_repository):
        self.tag_repository = tag_repository
    
    def attach_tags(self, artwork_id: int, tag_names: List[str]) -> None:
        """Прикрепляет теги к работе"""
        for tag_name in tag_names:
            tag = self.tag_repository.find_or_create(tag_name)
            self.tag_repository.attach_to_artwork(artwork_id, tag.id)
    
    def detach_tags(self, artwork_id: int, tag_names: List[str]) -> None:
        """Открепляет теги от работы"""
        for tag_name in tag_names:
            tag = self.tag_repository.find_by_name(tag_name)
            if tag:
                self.tag_repository.detach_from_artwork(artwork_id, tag.id)
    
    def get_tags(self, artwork_id: int) -> List[str]:
        """Получает теги работы"""
        tags = self.tag_repository.find_by_artwork(artwork_id)
        return [tag.name for tag in tags]


class NotificationService:
    """
    GRASP: High Cohesion
    Ответственность: Все уведомления в одном месте
    """
    
    def __init__(self, user_repository, notification_gateway):
        self.user_repository = user_repository
        self.notification_gateway = notification_gateway
    
    def notify_followers(self, user_id: int, artwork_id: int) -> None:
        """Уведомляет подписчиков о новой работе"""
        followers = self.user_repository.get_followers(user_id)
        for follower in followers:
            self.notification_gateway.send(
                follower.id,
                f"New artwork by user {user_id}"
            )
    
    def notify_user(self, user_id: int, message: str) -> None:
        """Отправляет уведомление пользователю"""
        self.notification_gateway.send(user_id, message)


class ArtworkService:
    """
    GRASP: Controller
    Ответственность: Координация создания работы
    """
    
    def __init__(
        self,
        repository: ArtworkRepository,
        validator: ArtworkValidator,
        file_storage: FileStorageService,
        tag_service: TagService,
        notification_service: NotificationService
    ):
        self.repository = repository
        self.validator = validator
        self.file_storage = file_storage
        self.tag_service = tag_service
        self.notification_service = notification_service
    
    def create_artwork(self, file, metadata: dict, tags: List[str] = None) -> Artwork:
        """
        Создает новую работу
        Применяет несколько GRASP шаблонов:
        - Controller: координирует процесс
        - Information Expert: использует валидатор для проверки
        - Creator: использует репозиторий для создания
        """
        # Валидация (Information Expert)
        validation = self.validator.validate(file, metadata)
        if not validation['valid']:
            raise ValueError(f"Validation failed: {validation['errors']}")
        
        # Загрузка файла (Low Coupling)
        file_path = self.file_storage.upload_file(file)
        
        # Создание объекта (Creator)
        artwork_data = {
            'user_id': metadata['user_id'],
            'title': metadata['title'],
            'description': metadata.get('description'),
            'file_path': file_path,
            'is_published': metadata.get('is_published', False)
        }
        artwork = self.repository.create(artwork_data)
        
        # Прикрепление тегов (High Cohesion)
        if tags:
            self.tag_service.attach_tags(artwork.id, tags)
        
        # Уведомление подписчиков (High Cohesion)
        self.notification_service.notify_followers(
            artwork.user_id,
            artwork.id
        )
        
        return artwork


# Пример использования
if __name__ == "__main__":
    # Инициализация зависимостей
    db = None  # Заглушка для БД
    repository = ArtworkRepository(db)
    validator = ArtworkValidator()
    file_storage = FileStorageService("/storage")
    tag_service = TagService(None)  # Заглушка
    notification_service = NotificationService(None, None)  # Заглушка
    
    # Создание сервиса
    service = ArtworkService(
        repository,
        validator,
        file_storage,
        tag_service,
        notification_service
    )
    
    # Использование
    # file = ...  # Файл для загрузки
    # metadata = {'user_id': 1, 'title': 'My Artwork'}
    # artwork = service.create_artwork(file, metadata, tags=['art', 'digital'])

