def test_readiness_when_dependencies_are_available(test_app):
    response = test_app.get('/ready')
    assert response.status_code == 200
    assert response.json()['status'] == 'ok'


def test_readiness_returns_503_without_ollama(test_app, mock_ollama, mock_qdrant):
    mock_ollama.health.return_value = False
    response = test_app.get('/ready')
    assert response.status_code == 503
    assert response.json()['services']['ollama'] == 'unavailable'
    mock_qdrant.ensure_collection.assert_not_awaited()


def test_readiness_recovers_collection_after_late_start(test_app, mock_qdrant):
    mock_qdrant.health.side_effect = [False, True]
    response = test_app.get('/ready')
    assert response.status_code == 200
    mock_qdrant.ensure_collection.assert_awaited_once_with(1024)


def test_readiness_does_not_hide_collection_initialization_failure(test_app, mock_qdrant):
    mock_qdrant.health.return_value = False
    mock_qdrant.ensure_collection.side_effect = ValueError('incompatible dimension')
    response = test_app.get('/ready')
    assert response.status_code == 503
