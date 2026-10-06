#!/usr/bin/env python3
"""Prüft die tägliche Höchstarbeitszeit eines Dienstes."""

import json
import sys

MAX_HOURS = 10.0
WARN_HOURS = 8.0
RULE_ID = "rule_001_max_daily_hours"


def dienst_stunden(start: str, end: str) -> float:
    start_h, start_m = (int(part) for part in start.split(":", 1))
    end_h, end_m = (int(part) for part in end.split(":", 1))
    start_min = start_h * 60 + start_m
    end_min = end_h * 60 + end_m
    if end_min <= start_min:
        end_min += 24 * 60
    return (end_min - start_min) / 60


def stunden_text(stunden: float) -> str:
    return f"{stunden:.1f}".replace(".", ",")


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError:
        print("Eingabe ist kein gültiges JSON.", file=sys.stderr)
        return 1

    dienste = payload.get("dienste")
    if not isinstance(dienste, list):
        print("Die Eingabe enthält keine Dienstliste.", file=sys.stderr)
        return 1

    violations = []
    for dienst in dienste:
        if not isinstance(dienst, dict):
            print("Ein Dienst hat das falsche Format.", file=sys.stderr)
            return 1
        try:
            stunden = dienst_stunden(str(dienst["start"]), str(dienst["end"]))
        except (KeyError, TypeError, ValueError):
            print("Ein Dienst enthält keine gültige Uhrzeit.", file=sys.stderr)
            return 1

        employee = str(dienst.get("employee", ""))
        date = str(dienst.get("date", ""))
        if stunden > MAX_HOURS:
            violations.append(
                {
                    "employee": employee,
                    "date": date,
                    "message": (
                        "Tägliche Höchstarbeitszeit von 10 Stunden überschritten "
                        f"({stunden_text(stunden)} Stunden)"
                    ),
                    "severity": "error",
                }
            )
        elif stunden > WARN_HOURS:
            violations.append(
                {
                    "employee": employee,
                    "date": date,
                    "message": f"Dienst liegt über 8 Stunden ({stunden_text(stunden)} Stunden)",
                    "severity": "warning",
                }
            )

    has_error = any(item["severity"] == "error" for item in violations)
    json.dump(
        {
            "rule": RULE_ID,
            "success": not has_error,
            "violations": violations,
        },
        sys.stdout,
        ensure_ascii=False,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
