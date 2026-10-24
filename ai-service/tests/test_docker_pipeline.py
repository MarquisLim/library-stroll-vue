"""Opt-in test of the running Docker API, real Ollama and persistent Qdrant.

RUN_DOCKER_AI_SMOKE=1 python -m pytest tests/test_docker_pipeline.py -q
Creates only a randomly identified temporary vector and removes it in finally.
"""
import os
import secrets

import httpx
import pytest


@pytest.mark.skipif(os.getenv('RUN_DOCKER_AI_SMOKE') != '1', reason='Real Docker AI pipeline is opt-in')
def test_running_docker_api_indexes_searches_and_deletes():
    artwork_id = 1_000_000_000_000 + secrets.randbelow(1_000_000_000)
    user_id = artwork_id
    artwork_ids = (artwork_id, artwork_id + 1)
    api_url = os.getenv('DOCKER_AI_API_URL', 'http://127.0.0.1:8001')
    headers = {'X-AI-Service-Key': os.getenv('AI_SERVICE_API_KEY', '')}
    with httpx.Client(base_url=api_url, headers=headers, timeout=180) as client:
        ready = client.get('/ready')
        ready.raise_for_status()
        assert ready.json()['status'] == 'ok'
        try:
            point_ids = {}
            for current_id in (*artwork_ids, artwork_id):
                response = client.post('/api/v1/artworks/index', json={
                    'artwork_id': current_id,
                    'metadata': {'summary': 'A watercolor painting of a cat, кот акварель'},
                    'searchable_text': 'A watercolor painting of a cat, кот акварель',
                    'payload': {
                        'user_id': user_id, 'is_published': True, 'is_private': False,
                        'is_blocked': False, 'media_type': 'image',
                    },
                    'content_hash': f'docker-smoke-{current_id}',
                })
                assert response.status_code == 200, response.text
                result = response.json()
                assert result['embedding_dimension'] > 0
                assert result['qdrant_point_id']
                if current_id in point_ids:
                    assert point_ids[current_id] == result['qdrant_point_id']
                point_ids[current_id] = result['qdrant_point_id']

            response = client.post('/api/v1/search', json={
                'query': 'кот акварель', 'limit': 5, 'filters': {'user_id': user_id},
            })
            assert response.status_code == 200, response.text
            assert {item['artwork_id'] for item in response.json()['results']} == set(artwork_ids)

            response = client.post('/api/v1/similar', json={
                'artwork_id': artwork_id, 'limit': 5, 'filters': {'user_id': user_id},
            })
            assert response.status_code == 200, response.text
            assert [item['artwork_id'] for item in response.json()['results']] == [artwork_id + 1]
        finally:
            for current_id in artwork_ids:
                deleted = client.delete(f'/api/v1/artworks/{current_id}/index')
                deleted.raise_for_status()

        response = client.post('/api/v1/search', json={
            'query': 'кот акварель', 'limit': 5, 'filters': {'user_id': user_id},
        })
        response.raise_for_status()
        assert response.json()['results'] == []
