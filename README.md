# trader man
sass --watch register.scss register.css

PYTHONWARNINGS=ignore python/venv/bin/python python/candle_storage.py --symbol ITC

PYTHONWARNINGS=ignore python/venv/bin/python python/candle_storage.py