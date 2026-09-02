@echo off
REM scripts/stress_run.bat — Windows wrapper for stress_run.sh.
REM Usage:
REM   scripts\stress_run.bat --phase=A --tier=sqlite
REM   scripts\stress_run.bat --phase=B --tier=mysql --workers=25

bash "%~dp0stress_run.sh" %*
