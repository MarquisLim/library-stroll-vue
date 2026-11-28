import re
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, model_validator

from app.schemas.requests import AssistantQuery


class QueryObjectModel(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)


Excerpt = Annotated[str, StringConstraints(min_length=1, max_length=96)]
Quantity = Annotated[int, Field(ge=0, le=1000)]
ObjectRef = Annotated[int, Field(ge=1, le=6)]

NUMBERS = {word: number for number, words in {
    0: "zero ноль", 1: "one один одна одно одного одну", 2: "two два две двух",
    3: "three три трех", 4: "four четыре четырех", 5: "five пять пяти", 6: "six шесть шести",
    7: "seven семь семи", 8: "eight восемь восьми", 9: "nine девять девяти", 10: "ten десять десяти",
}.items() for word in words.split()}
COUNT_CUES = {
    "at_least": r"(?:не менее|не меньше|минимум|как минимум|at least|no fewer than)",
    "at_most": r"(?:не более|не больше|максимум|до|at most|no more than|up to)",
    "approx": r"(?:примерно|около|приблизительно|about|roughly|around|approximately)",
    "range": r"(?:между|от|between|from|[-–—]|\bto\b)",
    "vague": r"(?:несколько|нескольких|много|several|a few|few|some|many)",
}
NEGATION = r"(?:без|кроме|не|а не|нет|without|no|not|except|excluding|sin|sans|なし|ない)"
PREFERENCE = r"(?:желательно|предпочтительно|лучше|preferably|ideally|if possible|de preferencia)"
BOUNDARY_CHAR = r"[a-zA-Z\u0400-\u052f0-9]"
TOKENS = re.compile(r"[\u3040-\u30ff\u3400-\u9fff]|\w+(?:[-’']\w+)*")


def source_span(text: str) -> str:
    return ((r"(?<!\w)" if re.match(BOUNDARY_CHAR, text[0]) else "") + re.escape(text)
            + (r"(?!\w)" if re.match(BOUNDARY_CHAR, text[-1]) else ""))


def grounded(text: str, query: str) -> bool:
    return (text == text.strip() and not re.search(r"[\x00-\x1f\x7f<>]|://", text)
            and re.search(source_span(text), query, re.IGNORECASE) is not None)


def has_cue(pattern: str, text: str) -> bool:
    return re.search(rf"(?<![a-zа-я0-9]){pattern}(?![a-zа-я0-9])", text, re.IGNORECASE) is not None


class QueryUnderstandingRequest(QueryObjectModel):
    query: AssistantQuery


class QueryCount(QueryObjectModel):
    op: Literal["exact", "at_least", "at_most", "range", "approx", "vague"]
    text: Excerpt
    n: Quantity | None = None
    max: Quantity | None = None

    @model_validator(mode="after")
    def check_shape(self):
        if self.op == "vague":
            valid = self.n is None and self.max is None
        elif self.op == "range":
            valid = self.n is not None and self.max is not None and self.max > self.n
        else:
            valid = self.n is not None and self.max is None
        if not valid:
            raise ValueError("Invalid quantity bounds")
        return self

    def check_grounding(self, query: str) -> None:
        text = self.text.lower().replace("ё", "е")
        numbers = [int(word) if word.isascii() and word.isdigit() else NUMBERS[word]
                   for word in re.findall(r"\w+", text) if word in NUMBERS or re.fullmatch(r"[0-9]{1,4}", word)]
        expected = [] if self.op == "vague" else [self.n, self.max] if self.op == "range" else [self.n]
        if not grounded(self.text, query) or numbers != expected:
            raise ValueError("Quantity is not grounded in the query")
        if self.op == "exact":
            non_exact = "|".join(COUNT_CUES.values())
            if has_cue(rf"(?:{non_exact})", text) or re.search(rf"(?:{non_exact})\s+{source_span(self.text)}", query, re.I):
                raise ValueError("Quantity qualifier was omitted")
        elif not has_cue(COUNT_CUES[self.op], text):
            raise ValueError("Quantity qualifier is not grounded")


class QueryObject(QueryObjectModel):
    name: Excerpt
    role: Literal["primary", "additional"] = "primary"
    mode: Literal["required", "preferred"] = "required"
    cue: Excerpt | None = None
    count: QueryCount | None = None


class QueryCondition(QueryObjectModel):
    kind: Literal["attribute", "color", "background_color", "media", "style", "scene", "relation", "presence"]
    text: Excerpt
    target: ObjectRef | None = None
    other: ObjectRef | None = None
    mode: Literal["required", "preferred", "excluded", "preferred_excluded"] = "required"
    cue: Excerpt | None = None

    @model_validator(mode="after")
    def check_scope(self):
        if ((self.target is not None) != (self.kind in {"attribute", "color", "relation"})
                or (self.other is not None) != (self.kind == "relation")
                or (self.other is not None and self.target == self.other)
                or (self.kind == "presence" and self.mode not in {"excluded", "preferred_excluded"})):
            raise ValueError("Invalid condition scope")
        return self


class QueryAmbiguity(QueryObjectModel):
    kind: Literal["missing_subject", "reference", "quantity", "attribute", "relation", "other"]
    text: Excerpt


class QueryUnderstandingPlan(QueryObjectModel):
    objects: list[QueryObject] = Field(max_length=6)
    conditions: list[QueryCondition] = Field(max_length=12)
    ambiguities: list[QueryAmbiguity] = Field(max_length=4)

    @classmethod
    def generation_schema(cls, query: str) -> dict:
        schema = cls.model_json_schema()
        tokens = list(TOKENS.finditer(query))
        excerpts: dict[str, None] = {}
        for length in range(1, 9):
            for index in range(len(tokens) - length + 1):
                span = query[tokens[index].start():tokens[index + length - 1].end()]
                if len(span) <= 96 and grounded(span, query):
                    excerpts.setdefault(span, None)
                if len(excerpts) == 384:
                    break
            if len(excerpts) == 384:
                break
        if excerpts:
            grounded_schema = {"type": "string", "enum": list(excerpts)}
            for definition, field in (("QueryObject", "name"), ("QueryCount", "text"),
                                      ("QueryCondition", "text"), ("QueryAmbiguity", "text")):
                schema["$defs"][definition]["properties"][field] = grounded_schema
            for definition in ("QueryObject", "QueryCondition"):
                schema["$defs"][definition]["properties"]["cue"] = {"anyOf": [grounded_schema, {"type": "null"}]}
        if not (any(token.group().lower().replace("ё", "е") in NUMBERS or token.group().isascii() and token.group().isdigit()
                    for token in tokens) or has_cue(COUNT_CUES["vague"], query)):
            schema["$defs"]["QueryObject"]["properties"]["count"] = {"type": "null"}

        # Make the scope required in the generation grammar as well as in model validation.
        condition = schema["$defs"]["QueryCondition"]
        variants = []
        for kinds, scope in ((["attribute", "color"], ["target"]), (["relation"], ["target", "other"]),
                             (["background_color", "media", "style", "scene", "presence"], [])):
            properties = {key: value for key, value in condition["properties"].items() if key not in {"target", "other"}}
            properties["kind"] = {"type": "string", "enum": kinds}
            for key in scope:
                properties[key] = {"type": "integer", "minimum": 1, "maximum": 6}
            variants.append({**condition, "properties": properties, "required": ["kind", "text", *scope]})
        schema["$defs"]["QueryCondition"] = {"anyOf": variants}
        return schema

    def check_grounding(self, query: str) -> None:
        def check_cue(mode: str, cue: str | None) -> None:
            valid = mode == "required" if cue is None else (
                grounded(cue, query)
                and (mode == "required" or (
                    ("preferred" not in mode or has_cue(PREFERENCE, cue))
                    and ("excluded" not in mode or has_cue(NEGATION, cue))
                ))
            )
            if not valid:
                raise ValueError("Preference or negation is not grounded")

        if len(set((item.kind, item.text, item.target, item.other, item.mode) for item in self.conditions)) != len(self.conditions):
            raise ValueError("Duplicate conditions")
        for item in self.objects:
            if not grounded(item.name, query):
                raise ValueError("Object is not grounded in the query")
            check_cue(item.mode, item.cue)
            if item.count is not None:
                item.count.check_grounding(query)
        for item in self.conditions:
            if not grounded(item.text, query) or max(item.target or 0, item.other or 0) > len(self.objects):
                raise ValueError("Condition is not grounded in the query")
            if item.kind == "background_color" and re.search(r"\bфон[а-я]*\b|\b(?:background|fondo|fond|against)\b|背景", query, re.I) is None:
                raise ValueError("No background was specified")
            check_cue(item.mode, item.cue)
        if any(not grounded(item.text, query) for item in self.ambiguities):
            raise ValueError("Ambiguity is not grounded in the query")
