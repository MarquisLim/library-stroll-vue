def test_health_returns_ok(test_app, mock_ollama, mock_qdrant):
    mock_ollama.health.return_value = True
    mock_qdrant.health.return_value = True

    resp = test_app.get("/health")
    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "ok"
    assert data["services"]["ollama"] == "ok"
    assert data["services"]["qdrant"] == "ok"


def test_health_degraded_when_ollama_down(test_app, mock_ollama, mock_qdrant):
    mock_ollama.health.return_value = False
    mock_qdrant.health.return_value = True

    resp = test_app.get("/health")
    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "degraded"
    assert data["services"]["ollama"] == "unavailable"
    assert data["services"]["qdrant"] == "ok"


def test_health_degraded_when_qdrant_down(test_app, mock_ollama, mock_qdrant):
    mock_ollama.health.return_value = True
    mock_qdrant.health.return_value = False

    resp = test_app.get("/health")
    data = resp.json()
    assert data["status"] == "degraded"
    assert data["services"]["qdrant"] == "unavailable"
