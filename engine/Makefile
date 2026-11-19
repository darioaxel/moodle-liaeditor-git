.PHONY: install test lint format typecheck check serve

install:
	pip install -e .[dev]

test:
	pytest

lint:
	ruff check .
	ruff format --check .

format:
	ruff format .

typecheck:
	mypy

check: lint typecheck test

serve:
	python -m courseascode.serve
