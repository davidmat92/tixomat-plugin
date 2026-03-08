"""
WSGI Entry Point for PythonAnywhere.

Configure PythonAnywhere to point to:
    /home/USERNAME/dp-connect-bot/dp_connect_bot/wsgi.py

With the application variable: app
"""

from dp_connect_bot.app import create_app

app = create_app()
