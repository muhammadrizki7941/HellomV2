@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-kasir-all.ps1" -Browser all
