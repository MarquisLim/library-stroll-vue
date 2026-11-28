import hashlib
import json
import unittest
from concurrent.futures import ThreadPoolExecutor, TimeoutError
from pathlib import Path
from tempfile import TemporaryDirectory
from threading import Event
from types import SimpleNamespace
from unittest.mock import Mock, patch

from fastapi.testclient import TestClient

import colbert_http
import colbert_state


def fake_encode(model, documents, ids):
    def vector(text):
        tokens = len(text.split())
        return SimpleNamespace(shape=(tokens, 128), numel=lambda: tokens * 128, element_size=lambda: 4)
    return {artwork_id: vector(documents[artwork_id]) for artwork_id in ids}


def fake_rerank(model, query, hits, vectors, synchronize):
    return [
        {"id": hit["id"], "colbert_score": float(vectors[hit["id"]].shape[0]), "colbert_rank": i}
        for i, hit in enumerate(hits, 1)
    ], 1.0, 0.0


class ColbertDocumentTest(unittest.TestCase):
    def setUp(self):
        self.encode = Mock(side_effect=fake_encode)
        self.rerank = Mock(side_effect=fake_rerank)
        self.model_load = Mock(return_value=(object(), 0.01))
        self.enterContext(patch.multiple(
            colbert_http,
            load_inputs=Mock(return_value=(
                {441040: "original red object", 441041: "second document"}, [], [{"results": [{"id": 441040}, {"id": 441041}]}],
            )),
            prepare_device=Mock(return_value=lambda: None),
            load_model=self.model_load,
            encode_documents=self.encode,
            rerank=self.rerank,
            runtime_memory=Mock(return_value={}),
        ))

    def client(self, dynamic=True, **options):
        return self.enterContext(self.new_client(dynamic, **options))

    @staticmethod
    def new_client(dynamic=True, state_dir=None, document_api_key=None, **options):
        app = colbert_http.create_app(
            Path("."), Path("."), "cpu", dynamic_documents=dynamic, state_dir=state_dir,
            document_api_key=document_api_key,
        )
        options.setdefault("base_url", "http://127.0.0.1")
        options.setdefault("client", ("127.0.0.1", 12345))
        return TestClient(app, **options)

    def state_dir(self):
        return Path(self.enterContext(TemporaryDirectory()))

    @staticmethod
    def metadata(client, artwork_id):
        return client.get(f"/api/v1/documents/{artwork_id}").json()

    @staticmethod
    def upsert(client, artwork_id, text, previous=None):
        previous = previous or {"content_hash": None, "version": None}
        return client.put(f"/api/v1/documents/{artwork_id}", json={
            "searchable_text": text, "expected_previous_hash": previous["content_hash"],
            "expected_previous_version": previous["version"],
        })

    @staticmethod
    def delete(client, artwork_id, previous):
        return client.delete(f"/api/v1/documents/{artwork_id}", params={
            "expected_previous_hash": previous["content_hash"],
            "expected_previous_version": previous["version"],
        })

    @staticmethod
    def rank(client, artwork_id):
        return client.post("/api/v1/rerank", json={"query": "red", "artwork_ids": [artwork_id], "limit": 1})

    def test_default_mode_has_no_document_operations(self):
        client = self.client(dynamic=False)
        self.assertEqual(client.get("/api/v1/documents/441040").status_code, 404)
        self.assertEqual(self.upsert(client, 441101, "new document").status_code, 404)
        self.assertEqual(self.rank(client, 441101).json(), {"detail": "Candidate ID is outside the frozen corpus"})
        self.assertNotIn("encoded_documents_total", client.get("/ready").json())

    def test_frozen_catalog_checks_manifest_and_per_candidate_keys_and_disables_mutation(self):
        directory = self.state_dir()
        digest = colbert_state.content_hash
        text = "a painting with delicate strokes"
        revision = "a" * 64
        key = digest(f"{revision}:{digest(text)}")
        corpus = [{"id": i, "text": text, "revision": revision, "text_sha256": digest(text), "document_key": key}
                  for i in range(1, 512)]
        raw = json.dumps(corpus).encode()
        document_path = directory / "documents.json"
        document_path.write_bytes(raw)
        (directory / "documents-manifest.json").write_text(json.dumps({
            "schema_version": 1, "documents_sha256": hashlib.sha256(raw).hexdigest(),
            "documents_bytes": len(raw), "document_count": 511,
        }), encoding="utf-8")
        app = colbert_http.create_app(directory, directory, "cpu", frozen_catalog=True)
        with TestClient(app) as client:
            payload = {"query": "a painting", "artwork_ids": list(range(1, 51)), "document_keys": [key] * 50, "limit": 50}
            valid = client.post("/api/v1/rerank", json=payload)
            self.assertEqual(valid.status_code, 200)
            self.assertEqual([hit["artwork_id"] for hit in valid.json()["results"]], payload["artwork_ids"])
            self.assertEqual(self.upsert(client, 1, "new text").status_code, 404)
            for patch_values, code in [
                ({"artwork_ids": [999, *range(2, 51)]}, 404),
                ({"document_keys": ["0" * 64, *([key] * 49)]}, 409),
                ({"artwork_ids": [2, *range(2, 51)]}, 422),
                ({"document_keys": [key]}, 422),
                ({"limit": 49}, 422),
                ({"extra": True}, 422),
            ]:
                self.assertEqual(client.post("/api/v1/rerank", json=payload | patch_values).status_code, code)
            self.rerank.assert_called_once()
            self.assertEqual(client.get("/ready").json()["document_count"], 511)
        document_path.write_bytes(raw + b" ")
        with self.assertRaisesRegex(ValueError, "checksum mismatch"):
            colbert_http.load_catalog(directory)

    def test_dynamic_routes_require_loopback_client_and_host(self):
        app = colbert_http.create_app(Path("."), Path("."), "cpu", dynamic_documents=True)
        with TestClient(app, base_url="http://127.0.0.1", client=("192.0.2.1", 12345)) as external:
            self.assertEqual(self.upsert(external, 441101, "new document").status_code, 403)
        local = self.client()
        self.assertEqual(local.get("/api/v1/documents/441040", headers={"Host": "remote.test"}).status_code, 403)

    def test_internal_documents_require_key_on_each_method(self):
        directory = self.state_dir()
        key = "synthetic-test-key"
        with self.new_client(state_dir=directory, document_api_key=key,
                             base_url="http://colbert", client=("192.0.2.1", 12345)) as client:
            path = "/api/v1/documents/441101"
            upsert = {"searchable_text": "new document", "expected_previous_hash": None, "expected_previous_version": None}
            deletion = {"expected_previous_hash": "0" * 64, "expected_previous_version": "0" * 32}
            for headers in ({}, {"X-ColBERT-Sync-Key": ""}, {"X-ColBERT-Sync-Key": "wrong-key"},
                            [("X-ColBERT-Sync-Key", key), ("X-ColBERT-Sync-Key", "wrong-key")]):
                for method, options in (("GET", {}), ("PUT", {"json": upsert}), ("DELETE", {"params": deletion})):
                    with self.subTest(method=method):
                        response = client.request(method, path, headers=headers, **options)
                        self.assertEqual(response.status_code, 401)
                        self.assertNotIn(key, response.text)
            self.assertEqual(self.encode.call_count, 1)
            self.assertEqual(client.get("/ready").status_code, 200)
            self.assertEqual(self.rank(client, 441040).status_code, 200)
            client.headers["X-ColBERT-Sync-Key"] = key
            self.assertFalse(self.metadata(client, 441101)["exists"])
            created = self.upsert(client, 441101, "new document").json()
            self.assertTrue(self.metadata(client, 441101)["exists"])
            self.assertEqual(self.delete(client, 441101, created).json()["status"], "deleted")

    def test_internal_documents_require_state_and_a_nonempty_key(self):
        directory = self.state_dir()
        for key in ("", "   ", "newline\n", "é"):
            with self.assertRaisesRegex(ValueError, "API key"):
                self.new_client(state_dir=directory, document_api_key=key)
        with self.assertRaisesRegex(ValueError, "require a state directory"):
            self.new_client(document_api_key="synthetic-test-key")

    def test_only_changed_document_is_encoded_and_replays_are_noops(self):
        client = self.client()
        other = client.app.state.document_vectors[441041]
        start = client.get("/ready").json()["startup_id"]
        original = self.metadata(client, 441040)
        changed = self.upsert(client, 441040, " blue object ", original).json()
        self.assertEqual(changed["content_hash"], hashlib.sha256(b" blue object ").hexdigest())
        self.assertEqual(changed["status"], "updated")
        self.assertNotEqual(changed["version"], original["version"])
        self.assertEqual(self.encode.call_args.args[2], [441040])
        self.assertIs(client.app.state.document_vectors[441041], other)
        retry = self.upsert(client, 441040, " blue object ", original).json()
        self.assertEqual(retry["status"], "unchanged")
        self.assertEqual(retry["encoded_documents"], 0)
        self.assertEqual(retry["version"], changed["version"])
        self.assertEqual(self.upsert(client, 441040, "newest text", original).status_code, 409)
        self.assertEqual(self.delete(client, 441040, original).status_code, 409)
        self.assertEqual(client.get("/ready").json()["encoded_documents_total"], 3)
        self.assertEqual(client.get("/ready").json()["startup_id"], start)
        self.model_load.assert_called_once()
        self.assertEqual(self.encode.call_count, 2)

    def test_delete_and_recreate_reject_old_versions_even_for_same_text(self):
        client = self.client()
        self.assertEqual(self.rank(client, 441101).status_code, 422)
        created = self.upsert(client, 441101, "new document").json()
        self.assertEqual(created["status"], "created")
        self.assertEqual(self.rank(client, 441101).json()["results"][0]["artwork_id"], 441101)
        removed = self.delete(client, 441101, created).json()
        self.assertEqual(removed["status"], "deleted")
        self.assertEqual(self.delete(client, 441101, created).json()["status"], "absent")
        self.assertEqual(self.rank(client, 441101).status_code, 422)
        self.assertEqual(self.upsert(client, 441101, "new document").status_code, 409)
        recreated = self.upsert(client, 441101, "new document", removed).json()
        self.assertEqual(recreated["content_hash"], created["content_hash"])
        self.assertNotEqual(recreated["version"], created["version"])
        self.assertEqual(self.upsert(client, 441101, "stale edit", created).status_code, 409)
        self.assertEqual(self.delete(client, 441101, created).status_code, 409)

    def test_failed_encoding_leaves_previous_revision(self):
        client = self.client(raise_server_exceptions=False)
        original = self.metadata(client, 441040)
        vector = client.app.state.document_vectors[441040]
        self.encode.side_effect = RuntimeError("simulated encoding failure")
        self.assertEqual(self.upsert(client, 441040, "new text", original).status_code, 500)
        self.assertEqual(self.metadata(client, 441040), original)
        self.assertIs(client.app.state.document_vectors[441040], vector)
        self.assertEqual(client.get("/ready").json()["encoded_documents_total"], 2)

    def test_competing_updates_accept_only_one_writer(self):
        client = self.client()
        original = self.metadata(client, 441040)
        with ThreadPoolExecutor(2) as pool:
            first = pool.submit(self.upsert, client, 441040, "first replacement", original)
            second = pool.submit(self.upsert, client, 441040, "second replacement", original)
            self.assertEqual(sorted((first.result(5).status_code, second.result(5).status_code)), [200, 409])
        self.assertEqual(self.encode.call_count, 2)

    def test_rerank_finishes_using_old_vector_before_delete(self):
        client = self.client()
        original = self.metadata(client, 441040)
        entered, release = Event(), Event()

        def blocked_rerank(model, query, hits, vectors, synchronize):
            entered.set()
            if not release.wait(5):
                raise TimeoutError("test did not release the rerank request")
            return fake_rerank(model, query, hits, vectors, synchronize)

        self.rerank.side_effect = blocked_rerank
        with ThreadPoolExecutor(2) as pool:
            query = pool.submit(self.rank, client, 441040)
            try:
                self.assertTrue(entered.wait(5))
                deletion = pool.submit(self.delete, client, 441040, original)
                with self.assertRaises(TimeoutError):
                    deletion.result(0.05)
                self.assertIn(441040, client.app.state.document_vectors)
            finally:
                release.set()
            self.assertEqual(query.result(5).status_code, 200)
            self.assertEqual(deletion.result(5).json()["status"], "deleted")
        self.assertEqual(self.rank(client, 441040).status_code, 422)

    def test_dynamic_without_state_still_discards_changes(self):
        with self.new_client() as client:
            self.assertEqual(self.upsert(client, 441101, "ephemeral document").status_code, 200)
        with self.new_client() as restarted:
            self.assertEqual(self.rank(restarted, 441101).status_code, 422)

    def test_restart_restores_only_overrides_and_preserves_versions_and_tombstones(self):
        directory = self.state_dir()
        path = directory / "state.json"
        with self.new_client(state_dir=directory) as client:
            original = self.metadata(client, 441040)
            created = self.upsert(client, 441101, "new document").json()
            updated = self.upsert(client, 441040, "a new café image", original).json()
            original_deleted = self.metadata(client, 441041)
            deleted = self.delete(client, 441041, original_deleted).json()

        self.encode.reset_mock()
        with self.new_client(state_dir=directory) as restarted:
            self.assertEqual(self.metadata(restarted, 441101)["version"], created["version"])
            self.assertEqual(self.rank(restarted, 441101).status_code, 200)
            observed = self.metadata(restarted, 441040)
            self.assertEqual(observed["content_hash"], updated["content_hash"])
            self.assertEqual(observed["version"], updated["version"])
            self.assertEqual(self.metadata(restarted, 441041)["version"], deleted["version"])
            self.assertEqual(self.rank(restarted, 441041).status_code, 422)
            self.assertEqual(self.upsert(restarted, 441040, "stale edit", original).status_code, 409)
            self.assertEqual(self.delete(restarted, 441040, original).status_code, 409)
            before = path.read_bytes()
            with patch.object(colbert_state.DocumentState, "save") as save:
                self.assertEqual(self.upsert(restarted, 441040, "a new café image", original).json()["status"], "unchanged")
                self.assertEqual(self.delete(restarted, 441041, original_deleted).json()["status"], "absent")
                save.assert_not_called()
            self.assertEqual(path.read_bytes(), before)
            self.assertEqual([call.args[2] for call in self.encode.call_args_list], [[441040, 441041], [441040], [441101]])
            self.assertEqual(self.encode.call_args_list[1].args[1], {441040: "a new café image"})
            restored = self.upsert(restarted, 441040, "original red object", updated).json()
            self.assertEqual(restored["content_hash"], original["content_hash"])
            self.delete(restarted, 441101, created).raise_for_status()

        self.encode.reset_mock()
        with self.new_client(state_dir=directory) as restarted:
            self.assertEqual(self.metadata(restarted, 441040)["version"], restored["version"])
            self.assertEqual(self.delete(restarted, 441040, original).status_code, 409)
            self.assertEqual(self.rank(restarted, 441101).status_code, 422)
            self.assertEqual(self.upsert(restarted, 441101, "replayed create").status_code, 409)
            self.assertEqual(self.encode.call_count, 1)

    def test_failed_replace_keeps_previous_state_and_live_representations(self):
        directory = self.state_dir()
        path = directory / "state.json"
        with self.new_client(state_dir=directory) as client:
            original = self.metadata(client, 441040)
            current = self.upsert(client, 441040, "persisted text", original).json()
            metadata = self.metadata(client, 441040)
            vector = client.app.state.document_vectors[441040]
            before = path.read_bytes()
            with patch.object(colbert_state.os, "replace", side_effect=OSError("simulated replace failure")):
                self.assertEqual(self.upsert(client, 441040, "uncommitted text", current).status_code, 503)
                self.assertEqual(self.delete(client, 441040, current).status_code, 503)
            self.assertEqual(path.read_bytes(), before)
            self.assertEqual(list(directory.glob(".state-*.tmp")), [])
            self.assertEqual(self.metadata(client, 441040), metadata)
            self.assertIs(client.app.state.document_vectors[441040], vector)
        with self.new_client(state_dir=directory) as restarted:
            self.assertEqual(self.metadata(restarted, 441040), metadata)
            self.assertEqual(self.rank(restarted, 441040).status_code, 200)

    def test_state_directory_rejects_another_owner_until_shutdown(self):
        directory = self.state_dir()
        with self.new_client(state_dir=directory) as first:
            state = self.metadata(first, 441040)
            self.model_load.reset_mock()
            with self.assertRaisesRegex(RuntimeError, "already in use"), self.new_client(state_dir=directory):
                self.fail("second owner started")
            self.model_load.assert_not_called()
        with self.new_client(state_dir=directory) as next_owner:
            self.assertEqual(self.metadata(next_owner, 441040), state)

    def test_state_rejects_mismatched_model_corpus_and_checksum_without_overwriting(self):
        directory = self.state_dir()
        path = directory / "state.json"
        with self.new_client(state_dir=directory):
            state = path.read_bytes()
        self.model_load.reset_mock()
        with patch.object(colbert_state, "MODEL_REVISION", "different"):
            with self.assertRaisesRegex(RuntimeError, "does not match"), self.new_client(state_dir=directory):
                self.fail("incompatible model started")
        with patch.object(colbert_http, "load_inputs", return_value=({441040: "different corpus"}, [], [])):
            with self.assertRaisesRegex(RuntimeError, "does not match"), self.new_client(state_dir=directory):
                self.fail("incompatible corpus started")
        self.assertEqual(path.read_bytes(), state)
        corrupted = state.replace(b'"format_version":1', b'"format_version":2')
        self.assertNotEqual(state, corrupted)
        path.write_bytes(corrupted)
        with self.assertRaisesRegex(RuntimeError, "Invalid ColBERT state"), self.new_client(state_dir=directory):
            self.fail("corrupted state started")
        self.assertEqual(path.read_bytes(), corrupted)
        self.model_load.assert_not_called()


if __name__ == "__main__":
    unittest.main()
