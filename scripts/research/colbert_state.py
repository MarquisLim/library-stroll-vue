"""Persist document revisions for the local ColBERT research service."""

import hashlib
import json
import os
from contextlib import contextmanager, suppress
from pathlib import Path
from tempfile import NamedTemporaryFile
from typing import Annotated, Literal

from filelock import FileLock, Timeout
from pydantic import BaseModel, ConfigDict, PositiveInt, StringConstraints

from colbert_rerank import CODE_REVISION, MODEL_ID, MODEL_REVISION, WEIGHTS_SHA256


Hash = Annotated[str, StringConstraints(pattern=r"^[0-9a-f]{64}$")]
Version = Annotated[str, StringConstraints(pattern=r"^[0-9a-f]{32}$")]


class DocumentRevision(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)

    content_hash: Hash | None
    version: Version | None
    searchable_text: str | None = None

    def matches(self, digest: str | None, version: str | None) -> bool:
        return self.content_hash == digest and self.version == version


ABSENT = DocumentRevision(content_hash=None, version=None)


class StateSnapshot(BaseModel):
    model_config = ConfigDict(extra="forbid")

    format_version: Literal[1]
    identity: dict[str, str]
    documents: dict[PositiveInt, DocumentRevision]


def content_hash(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def canonical_json(data) -> str:
    return json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


class DocumentState:
    def __init__(self, directory: Path, base_hashes: dict[int, str]):
        self.path = directory / "state.json"
        self.base_hashes = base_hashes
        self.identity = {
            "model": MODEL_ID,
            "model_revision": MODEL_REVISION,
            "code_revision": CODE_REVISION,
            "weights_sha256": WEIGHTS_SHA256,
            "corpus_sha256": content_hash(canonical_json(base_hashes)),
        }

    @contextmanager
    def exclusive(self):
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            lock = FileLock(self.path.with_suffix(".lock"), timeout=0)
            lock.acquire()
        except Timeout as error:
            raise RuntimeError(f"ColBERT state directory is already in use: {self.path.parent}") from error
        except OSError as error:
            raise RuntimeError(f"Cannot lock ColBERT state directory: {self.path.parent}") from error
        try:
            yield
        finally:
            lock.release()

    def load(self) -> dict[int, DocumentRevision] | None:
        try:
            text = self.path.read_text(encoding="utf-8")
        except FileNotFoundError:
            return None
        except (OSError, UnicodeError) as error:
            raise RuntimeError(f"Cannot read ColBERT state: {self.path}") from error
        try:
            envelope = json.loads(text)
            data = envelope["snapshot"]
            if envelope["sha256"] != content_hash(canonical_json(data)):
                raise ValueError("checksum mismatch")
            snapshot = StateSnapshot.model_validate(data)
        except (ValueError, TypeError, KeyError) as error:
            raise RuntimeError(f"Invalid ColBERT state: {self.path}") from error

        if snapshot.identity != self.identity:
            raise RuntimeError(f"ColBERT state does not match the model or original corpus: {self.path}")
        if not self.base_hashes.keys() <= snapshot.documents.keys():
            raise RuntimeError(f"ColBERT state is missing original document revisions: {self.path}")
        for artwork_id, revision in snapshot.documents.items():
            text = revision.searchable_text
            if text is not None:
                valid = 0 < len(text) <= 50_000 and bool(text.strip()) and content_hash(text) == revision.content_hash
            else:
                valid = revision.content_hash is None or self.base_hashes.get(artwork_id) == revision.content_hash
            if not valid or revision.version is None:
                raise RuntimeError(f"Invalid ColBERT document {artwork_id} in state: {self.path}")
        return snapshot.documents

    def save(self, documents: dict[int, DocumentRevision]) -> None:
        snapshot = StateSnapshot(format_version=1, identity=self.identity, documents=documents).model_dump(mode="json")
        envelope = {"snapshot": snapshot, "sha256": content_hash(canonical_json(snapshot))}
        temporary = None
        try:
            with NamedTemporaryFile(mode="w", encoding="utf-8", dir=self.path.parent,
                                    prefix=".state-", suffix=".tmp", delete=False) as stream:
                temporary = Path(stream.name)
                stream.write(canonical_json(envelope) + "\n")
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self.path)
        finally:
            if temporary is not None:
                with suppress(OSError):
                    temporary.unlink(missing_ok=True)
