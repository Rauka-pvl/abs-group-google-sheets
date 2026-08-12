"""WSGI entrypoint for Passenger / gunicorn / Plesk.

Use:
  passenger_wsgi / wsgi: application
or:
  gunicorn wsgi:application
"""

from app.server import app

application = app
