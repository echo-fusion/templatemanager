{% extends "layouts/base" %}
{% set heading = 'H' %}
{% block title %}Child {{ heading }}{% endblock %}
{% block content %}<main>{{ body }}</main>{% endblock %}
