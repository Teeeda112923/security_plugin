from app.versioning import compare, in_range


def test_compare_basic():
    assert compare("5.9", "5.9.5") == -1
    assert compare("5.9.5", "5.9") == 1
    assert compare("8.8.3", "8.8.3") == 0
    assert compare("10.0", "9.9") == 1  # numeric, not lexicographic


def test_in_range_upper_exclusive():
    # affected up to (but not including) 5.9.5  -> 5.9 is affected, 5.9.5 is not
    rng = {"from": None, "to": "5.9.5", "to_incl": False}
    assert in_range("5.9", rng) is True
    assert in_range("5.9.5", rng) is False
    assert in_range("6.0", rng) is False


def test_in_range_bounded():
    rng = {"from": "4.0", "from_incl": True, "to": "4.5", "to_incl": True}
    assert in_range("4.0", rng) is True
    assert in_range("4.5", rng) is True
    assert in_range("3.9", rng) is False
    assert in_range("4.6", rng) is False
