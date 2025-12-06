#!/usr/bin/env python3
"""
Генератор UML диаграмм из DFD описания
Преобразует DFD процессы в UML классы и методы
"""

import json
from typing import Dict, List, Optional
from dataclasses import dataclass, asdict


@dataclass
class DFDProcess:
    """Представление процесса DFD"""
    id: str
    name: str
    inputs: List[str]
    outputs: List[str]
    data_stores: List[str] = None
    external_entities: List[str] = None


@dataclass
class UMLClass:
    """Представление UML класса"""
    name: str
    methods: List[Dict[str, str]]
    attributes: List[str] = None
    associations: List[str] = None


@dataclass
class UMLMethod:
    """Представление UML метода"""
    name: str
    parameters: List[Dict[str, str]]
    return_type: str
    visibility: str = "public"


class DFDToUMLTranslator:
    """Класс для трансляции DFD в UML"""
    
    def __init__(self):
        self.classes: Dict[str, UMLClass] = {}
        self.sequence_diagrams: List[Dict] = []
    
    def translate_process_to_class(self, process: DFDProcess) -> UMLClass:
        """
        Транслирует DFD процесс в UML класс
        
        Правило: Процесс с состоянием или данными → Класс
        """
        class_name = self._process_name_to_class_name(process.name)
        
        # Создаем методы из входов и выходов
        methods = []
        
        # Основной метод обработки
        if process.inputs and process.outputs:
            method_name = self._process_name_to_method_name(process.name)
            parameters = [{"name": inp.lower().replace(" ", "_"), "type": "Object"} 
                         for inp in process.inputs]
            return_type = process.outputs[0] if process.outputs else "void"
            
            methods.append({
                "name": method_name,
                "parameters": parameters,
                "return_type": return_type,
                "visibility": "public"
            })
        
        # Методы для работы с хранилищами данных
        if process.data_stores:
            for store in process.data_stores:
                methods.extend(self._create_data_store_methods(store))
        
        return UMLClass(
            name=class_name,
            methods=methods,
            attributes=process.data_stores or [],
            associations=process.external_entities or []
        )
    
    def translate_process_to_method(self, process: DFDProcess, parent_class: str) -> UMLMethod:
        """
        Транслирует DFD процесс в UML метод
        
        Правило: Элементарный процесс → Метод класса
        """
        method_name = self._process_name_to_method_name(process.name)
        parameters = [{"name": inp.lower().replace(" ", "_"), "type": "Object"} 
                     for inp in process.inputs]
        return_type = process.outputs[0] if process.outputs else "void"
        
        return UMLMethod(
            name=method_name,
            parameters=parameters,
            return_type=return_type,
            visibility="public"
        )
    
    def translate_data_store_to_class(self, store_name: str) -> UMLClass:
        """
        Транслирует хранилище данных в Repository класс
        
        Правило: Хранилище данных → Repository класс с CRUD методами
        """
        class_name = f"{store_name}Repository"
        
        methods = [
            {
                "name": "create",
                "parameters": [{"name": "data", "type": "Object"}],
                "return_type": "Object",
                "visibility": "public"
            },
            {
                "name": "findById",
                "parameters": [{"name": "id", "type": "int"}],
                "return_type": "Object",
                "visibility": "public"
            },
            {
                "name": "update",
                "parameters": [
                    {"name": "id", "type": "int"},
                    {"name": "data", "type": "Object"}
                ],
                "return_type": "Object",
                "visibility": "public"
            },
            {
                "name": "delete",
                "parameters": [{"name": "id", "type": "int"}],
                "return_type": "void",
                "visibility": "public"
            }
        ]
        
        return UMLClass(
            name=class_name,
            methods=methods,
            attributes=[],
            associations=["Database"]
        )
    
    def create_sequence_diagram(self, process_chain: List[DFDProcess]) -> Dict:
        """
        Создает диаграмму последовательности из цепочки процессов
        
        Правило: Последовательность процессов → Sequence Diagram
        """
        participants = []
        messages = []
        
        for i, process in enumerate(process_chain):
            class_name = self._process_name_to_class_name(process.name)
            participants.append(class_name)
            
            if i > 0:
                prev_class = self._process_name_to_class_name(process_chain[i-1].name)
                method_name = self._process_name_to_method_name(process.name)
                messages.append({
                    "from": prev_class,
                    "to": class_name,
                    "message": method_name,
                    "parameters": process.inputs
                })
        
        return {
            "name": f"Sequence_{process_chain[0].name}",
            "participants": participants,
            "messages": messages
        }
    
    def _process_name_to_class_name(self, process_name: str) -> str:
        """Преобразует имя процесса в имя класса"""
        # Убираем номер процесса (например, "2.1 Валидация" → "Валидация")
        name = process_name.split(".", 1)[-1].strip()
        # Убираем "Процесс", "Обработка" и т.д.
        name = name.replace("Процесс ", "").replace("Обработка ", "")
        # Преобразуем в CamelCase
        words = name.split()
        return "".join(word.capitalize() for word in words)
    
    def _process_name_to_method_name(self, process_name: str) -> str:
        """Преобразует имя процесса в имя метода"""
        class_name = self._process_name_to_class_name(process_name)
        # Первая буква в нижний регистр
        return class_name[0].lower() + class_name[1:] if class_name else "process"
    
    def _create_data_store_methods(self, store_name: str) -> List[Dict]:
        """Создает методы для работы с хранилищем данных"""
        return [
            {
                "name": f"saveTo{store_name}",
                "parameters": [{"name": "data", "type": "Object"}],
                "return_type": "void",
                "visibility": "public"
            },
            {
                "name": f"getFrom{store_name}",
                "parameters": [{"name": "id", "type": "int"}],
                "return_type": "Object",
                "visibility": "public"
            }
        ]


def generate_uml_from_dfd(dfd_json: Dict) -> Dict:
    """
    Главная функция генерации UML из DFD
    
    Args:
        dfd_json: JSON описание DFD модели
        
    Returns:
        Словарь с UML диаграммами
    """
    translator = DFDToUMLTranslator()
    
    # Извлекаем процессы из DFD
    processes = []
    for proc_data in dfd_json.get("processes", []):
        process = DFDProcess(
            id=proc_data["id"],
            name=proc_data["name"],
            inputs=proc_data.get("inputs", []),
            outputs=proc_data.get("outputs", []),
            data_stores=proc_data.get("data_stores", []),
            external_entities=proc_data.get("external_entities", [])
        )
        processes.append(process)
    
    # Транслируем процессы в классы
    uml_classes = []
    for process in processes:
        uml_class = translator.translate_process_to_class(process)
        translator.classes[uml_class.name] = uml_class
        uml_classes.append(asdict(uml_class))
    
    # Транслируем хранилища данных
    data_stores = dfd_json.get("data_stores", [])
    for store in data_stores:
        store_class = translator.translate_data_store_to_class(store)
        translator.classes[store_class.name] = store_class
        uml_classes.append(asdict(store_class))
    
    # Создаем диаграммы последовательности
    process_chains = dfd_json.get("process_chains", [])
    sequences = []
    for chain_data in process_chains:
        chain_processes = [
            p for p in processes if p.id in chain_data["process_ids"]
        ]
        sequence = translator.create_sequence_diagram(chain_processes)
        sequences.append(sequence)
    
    return {
        "classes": uml_classes,
        "sequence_diagrams": sequences
    }


# Пример использования
if __name__ == "__main__":
    # Пример DFD описания для процесса "Управление работами"
    dfd_example = {
        "processes": [
            {
                "id": "2.1",
                "name": "2.1 Валидация файла",
                "inputs": ["Файл"],
                "outputs": ["Валидный файл"],
                "data_stores": [],
                "external_entities": []
            },
            {
                "id": "2.2",
                "name": "2.2 Обработка изображения",
                "inputs": ["Изображение"],
                "outputs": ["Обработанное изображение"],
                "data_stores": [],
                "external_entities": []
            },
            {
                "id": "2.3",
                "name": "2.3 Сохранение в хранилище",
                "inputs": ["Файл", "Путь"],
                "outputs": ["Путь к файлу"],
                "data_stores": ["Файловое хранилище"],
                "external_entities": []
            },
            {
                "id": "2.4",
                "name": "2.4 Создание записи в БД",
                "inputs": ["Метаданные", "Путь к файлу"],
                "outputs": ["ID работы"],
                "data_stores": ["База данных"],
                "external_entities": []
            }
        ],
        "data_stores": ["База данных", "Файловое хранилище"],
        "process_chains": [
            {
                "name": "Загрузка работы",
                "process_ids": ["2.1", "2.2", "2.3", "2.4"]
            }
        ]
    }
    
    # Генерируем UML
    uml_result = generate_uml_from_dfd(dfd_example)
    
    # Выводим результат
    print("=== UML Классы ===")
    print(json.dumps(uml_result["classes"], indent=2, ensure_ascii=False))
    
    print("\n=== UML Диаграммы последовательности ===")
    print(json.dumps(uml_result["sequence_diagrams"], indent=2, ensure_ascii=False))

