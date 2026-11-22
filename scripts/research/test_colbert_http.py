import hashlib
import unittest
from concurrent.futures import ThreadPoolExecutor, TimeoutError
from pathlib import Path
from threading import Event
from types import SimpleNamespace
from unittest.mock import Mock, patch

from fastapi.testclient import TestClient

import colbert_http


def fake_encode(model, documents, ids):
    return {artwork_id: SimpleNamespace(shape=(len(documents[artwork_id].split()), 128)) for artwork_id in ids}


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
        app = colbert_http.create_app(Path("."), Path("."), "cpu", dynamic_documents=dynamic)
        return self.enterContext(TestClient(app, base_url="http://127.0.0.1", client=("127.0.0.1", 12345), **options))

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

    def test_dynamic_routes_require_loopback_client_and_host(self):
        app = colbert_http.create_app(Path("."), Path("."), "cpu", dynamic_documents=True)
        with TestClient(app, base_url="http://127.0.0.1", client=("192.0.2.1", 12345)) as external:
            self.assertEqual(self.upsert(external, 441101, "new document").status_code, 403)
        local = self.client()
        self.assertEqual(local.get("/api/v1/documents/441040", headers={"Host": "remote.test"}).status_code, 403)

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


if __name__ == "__main__":
    unittest.main()
