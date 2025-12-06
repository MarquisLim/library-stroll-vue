# Архитектура приложения с применением GRASP шаблонов

## Описание

Архитектура системы Library Stroll построена с применением принципов GRASP для обеспечения низкой связанности, высокой связности и четкого распределения ответственности.

## Архитектурные слои

### 1. Presentation Layer (Слой представления)
- **Ответственность:** Отображение данных пользователю
- **Компоненты:** Vue.js компоненты, React Native экраны
- **GRASP:** Low Coupling - слабая связь с бизнес-логикой

### 2. API Layer (Слой API)
- **Ответственность:** Обработка HTTP запросов
- **Компоненты:** Laravel Controllers
- **GRASP:** Controller - координация запросов

### 3. Business Logic Layer (Слой бизнес-логики)
- **Ответственность:** Реализация бизнес-правил
- **Компоненты:** Services (ArtworkService, CommentService и т.д.)
- **GRASP:** High Cohesion - каждый сервис отвечает за одну область

### 4. Data Access Layer (Слой доступа к данным)
- **Ответственность:** Работа с данными
- **Компоненты:** Repositories
- **GRASP:** Creator - создание объектов домена

### 5. Infrastructure Layer (Инфраструктурный слой)
- **Ответственность:** Технические сервисы
- **Компоненты:** FileStorage, Cache, Queue
- **GRASP:** Low Coupling - изоляция технических деталей

## Применение GRASP шаблонов

### Information Expert

**Применение:** Валидаторы знают правила валидации

```python
class ArtworkValidator:
    def validate(self, file, metadata):
        # Эксперт по валидации - знает все правила
        errors = []
        errors.extend(self._validate_file(file))
        errors.extend(self._validate_metadata(metadata))
        return ValidationResult(errors)
```

### Creator

**Применение:** Репозитории создают объекты домена

```python
class ArtworkRepository:
    def create(self, data):
        # Создатель - имеет все данные для создания
        artwork = Artwork(
            user_id=data['user_id'],
            title=data['title'],
            file_path=data['file_path']
        )
        self.db.save(artwork)
        return artwork
```

### Controller

**Применение:** Контроллеры координируют работу

```python
class ArtworkController:
    def create(self, request):
        # Контроллер - координирует валидацию и создание
        validation = self.validator.validate(request)
        if not validation.is_valid:
            return Response(400, errors=validation.errors)
        
        artwork = self.service.create_artwork(request)
        return Response(201, data=artwork)
```

### Low Coupling

**Применение:** Модули слабо связаны друг с другом

```python
class ModerationService:
    # Изолирован от основной бизнес-логики
    def requires_moderation(self, text):
        return self.spam_detector.is_spam(text)
```

### High Cohesion

**Применение:** Каждый сервис отвечает за одну область

```python
class NotificationService:
    # Все методы связаны с уведомлениями
    def notify_followers(self, user_id, artwork_id):
        pass
    
    def notify_user(self, user_id, message):
        pass
```

## Преимущества архитектуры

1. **Модульность** - каждый компонент имеет четкую ответственность
2. **Тестируемость** - легко тестировать изолированные компоненты
3. **Расширяемость** - легко добавлять новую функциональность
4. **Поддерживаемость** - изменения локализованы в конкретных модулях

