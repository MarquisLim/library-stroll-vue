from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, model_validator

from app.schemas.requests import AssistantQuery

AssistantSourceRef = Annotated[str, StringConstraints(pattern=r"^s[1-5]$", min_length=2, max_length=2)]


class AssistantFactRef(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    source_ref: AssistantSourceRef
    fact_ref: Annotated[str, StringConstraints(pattern=r"^f(?:[1-9]|1[01])$", min_length=2, max_length=3)]

    def pair(self) -> tuple[str, str]:
        return self.source_ref, self.fact_ref


class AssistantFact(AssistantFactRef):
    value: Annotated[AssistantQuery, StringConstraints(max_length=240)]
    origin: Literal["user_metadata", "ai_analysis"]

    @model_validator(mode="after")
    def check_origin(self):
        expected = "user_metadata" if self.fact_ref in {"f1", "f2", "f7"} else "ai_analysis"
        if self.origin != expected:
            raise ValueError("Unexpected origin for fact reference")
        return self


class AssistantComposeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    query: AssistantQuery
    facts: list[AssistantFact] = Field(min_length=1, max_length=35)
    packing: Literal["legacy", "visual_priority"] = "legacy"

    @model_validator(mode="after")
    def check_unique_facts(self):
        if len({fact.pair() for fact in self.facts}) != len(self.facts):
            raise ValueError("Duplicate facts")
        return self

    def prompt_data(self) -> dict:
        if self.packing == "legacy":
            return self.model_dump(exclude={"packing"})

        # This compact layout must match AssistantRagContext::inputBytes in Laravel.
        sources: dict[str, dict[str, str]] = {}
        for fact in self.facts:
            source = sources.setdefault(fact.source_ref, {"source_ref": fact.source_ref})
            source[fact.fact_ref] = f"Palette colors: {fact.value}" if fact.fact_ref == "f8" else fact.value
        return {"query": self.query, "facts": list(sources.values())}


class AssistantComposePlan(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    action: Literal["compose", "insufficient_evidence"]
    selections: list[AssistantFactRef] = Field(max_length=5)

    @model_validator(mode="after")
    def check_selection(self):
        if bool(self.selections) != (self.action == "compose"):
            raise ValueError("Selections do not match action")
        if len({selection.pair() for selection in self.selections}) != len(self.selections):
            raise ValueError("Duplicate selections")
        return self


class AssistantFollowupRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    query: AssistantQuery
    previous_queries: list[AssistantQuery] = Field(max_length=4)
    facts: list[AssistantFact] = Field(max_length=35)

    @model_validator(mode="after")
    def check_unique_facts(self):
        if len({fact.pair() for fact in self.facts}) != len(self.facts):
            raise ValueError("Duplicate facts")
        return self


class AssistantFollowupPlan(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    action: Literal["new_search", "use_previous", "clarify"]
    source_refs: list[AssistantSourceRef] = Field(max_length=5)

    @model_validator(mode="after")
    def check_sources(self):
        if bool(self.source_refs) != (self.action == "use_previous"):
            raise ValueError("Source references do not match action")
        if len(set(self.source_refs)) != len(self.source_refs):
            raise ValueError("Duplicate source references")
        return self
