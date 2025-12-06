#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Пример использования генератора DFD → UML
Демонстрирует работу генератора на примере процесса "Управление работами"
"""

import sys
import os
import json
import io

# Устанавливаем UTF-8 для вывода в Windows
if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# Добавляем путь к модулю генератора
generator_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, generator_dir)

# Импортируем модуль генератора
import importlib.util
spec = importlib.util.spec_from_file_location("dfd_to_uml", os.path.join(generator_dir, "dfd-to-uml.py"))
dfd_to_uml = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dfd_to_uml)

generate_uml_from_dfd = dfd_to_uml.generate_uml_from_dfd


def print_separator(title):
    """Печатает разделитель с заголовком"""
    print("\n" + "=" * 60)
    print(f"  {title}")
    print("=" * 60)


def print_dfd_process(process):
    """Красиво выводит DFD процесс"""
    print(f"\n📋 Процесс {process['id']}: {process['name']}")
    print(f"   Входы: {', '.join(process.get('inputs', []))}")
    print(f"   Выходы: {', '.join(process.get('outputs', []))}")
    if process.get('data_stores'):
        print(f"   Хранилища: {', '.join(process['data_stores'])}")


def print_uml_class(uml_class):
    """Красиво выводит UML класс"""
    print(f"\n🏛️  Класс: {uml_class['name']}")
    if uml_class.get('attributes'):
        print(f"   Атрибуты: {', '.join(uml_class['attributes'])}")
    if uml_class.get('associations'):
        print(f"   Ассоциации: {', '.join(uml_class['associations'])}")
    print("   Методы:")
    for method in uml_class.get('methods', []):
        params = ', '.join([f"{p['name']}: {p['type']}" for p in method.get('parameters', [])])
        return_type = method.get('return_type', 'void')
        visibility = method.get('visibility', 'public')
        print(f"     {visibility} {method['name']}({params}): {return_type}")


def print_sequence_diagram(sequence):
    """Красиво выводит диаграмму последовательности"""
    print(f"\n📊 Диаграмма последовательности: {sequence['name']}")
    print("   Участники:")
    for i, participant in enumerate(sequence.get('participants', []), 1):
        print(f"     {i}. {participant}")
    print("   Сообщения:")
    for i, message in enumerate(sequence.get('messages', []), 1):
        params = ', '.join(message.get('parameters', []))
        print(f"     {i}. {message['from']} → {message['to']}: {message['message']}({params})")


def main():
    """Главная функция демонстрации"""
    
    print_separator("ГЕНЕРАТОР DFD → UML")
    print("Демонстрация работы генератора на примере процесса 'Управление работами'")
    
    # Загружаем пример DFD описания
    example_file = os.path.join(os.path.dirname(__file__), 'example_dfd.json')
    
    print_separator("ШАГ 1: Загрузка DFD описания")
    print(f"Файл: {example_file}")
    
    with open(example_file, 'r', encoding='utf-8') as f:
        dfd_data = json.load(f)
    
    print(f"\n✅ Загружено процессов: {len(dfd_data['processes'])}")
    print(f"✅ Хранилищ данных: {len(dfd_data['data_stores'])}")
    print(f"✅ Цепочек процессов: {len(dfd_data['process_chains'])}")
    
    print_separator("ШАГ 2: DFD Процессы (входные данные)")
    for process in dfd_data['processes']:
        print_dfd_process(process)
    
    print_separator("ШАГ 3: Генерация UML модели")
    print("Выполняется трансформация DFD → UML...")
    
    # Генерируем UML
    uml_result = generate_uml_from_dfd(dfd_data)
    
    print("✅ Генерация завершена!")
    print(f"✅ Создано классов: {len(uml_result['classes'])}")
    print(f"✅ Создано диаграмм последовательности: {len(uml_result['sequence_diagrams'])}")
    
    print_separator("ШАГ 4: UML Классы (результат трансформации)")
    for uml_class in uml_result['classes']:
        print_uml_class(uml_class)
    
    print_separator("ШАГ 5: UML Диаграммы последовательности")
    for sequence in uml_result['sequence_diagrams']:
        print_sequence_diagram(sequence)
    
    print_separator("ШАГ 6: Сохранение результата")
    output_file = os.path.join(os.path.dirname(__file__), 'generated_uml.json')
    
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(uml_result, f, indent=2, ensure_ascii=False)
    
    print(f"✅ Результат сохранен в: {output_file}")
    
    print_separator("СРАВНЕНИЕ: DFD -> UML")
    print("\nПример трансформации:")
    print("\n📋 DFD Процесс:")
    print("   '2.1 Валидация файла'")
    print("   Входы: ['Файл']")
    print("   Выходы: ['Валидный файл']")
    print("\n   ↓ ТРАНСФОРМАЦИЯ ↓\n")
    print("🏛️  UML Класс:")
    print("   FileValidator")
    print("   Методы:")
    print("     public validate(file: Object): Валидный файл")
    
    print_separator("ДЕМОНСТРАЦИЯ ЗАВЕРШЕНА")
    print("\n✅ Генератор успешно преобразовал DFD описание в UML модель!")
    print("✅ Все классы и диаграммы созданы автоматически.")


if __name__ == "__main__":
    main()

