#!/usr/bin/env python3
"""
Facebook Prophet only: daily total bags + expected revenue (avg ₱/bag).
No non-Prophet forecast paths — failures return errors for the PHP layer.
Post-process: business floor so active demand does not collapse to zero.
"""
from __future__ import annotations

import json
import logging
import sys
import warnings
from typing import Any, Dict, List, Tuple

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")
logging.getLogger("prophet").setLevel(logging.ERROR)
try:
    logging.getLogger("cmdstanpy").setLevel(logging.CRITICAL)
except Exception:
    pass


def _safe_float(x: Any) -> float:
    try:
        return float(x)
    except (TypeError, ValueError):
        return 0.0


def rows_to_daily_df(rows: List[Dict[str, Any]], col: str) -> pd.DataFrame:
    if not rows:
        return pd.DataFrame(columns=["ds", "y"])
    df = pd.DataFrame(rows)
    df["ds"] = pd.to_datetime(df["date"], errors="coerce")
    df["y"] = df[col].map(_safe_float).clip(lower=0.0)
    df = df.dropna(subset=["ds"])
    df = df.groupby("ds", as_index=False)["y"].sum().sort_values("ds")
    if df.empty:
        return pd.DataFrame(columns=["ds", "y"])
    full = pd.date_range(df["ds"].min(), df["ds"].max(), freq="D")
    idx_df = pd.DataFrame({"ds": full})
    df = idx_df.merge(df, on="ds", how="left").fillna({"y": 0.0})
    return df


def _reference_demand(y: pd.Series) -> float:
    """Typical recent demand level for flooring (bags/day)."""
    yv = y.astype(float).values
    if len(yv) == 0:
        return 0.0
    tail = yv[-min(28, len(yv)) :]
    ref_tail = float(np.mean(tail))
    pos = yv[yv > 0.01]
    ref_pos = float(np.mean(pos[-min(14, len(pos)) :])) if len(pos) else 0.0
    med = float(np.median(yv))
    return max(ref_tail, ref_pos, med, 0.0)


def apply_business_floor(fc: pd.DataFrame, hist_y: pd.Series) -> pd.DataFrame:
    """
    When history shows real volume, do not let Prophet drag the business to ~0 overnight.
    Floor ≈ max(1 bag, 15% of reference demand); lower band stays coherent with yhat.
    """
    ref = _reference_demand(hist_y)
    if ref <= 0.01:
        fc = fc.copy()
        fc["yhat"] = fc["yhat"].clip(lower=0.0)
        fc["yhat_lower"] = fc["yhat_lower"].clip(lower=0.0)
        fc["yhat_upper"] = np.maximum(fc["yhat_upper"], fc["yhat"])
        return fc

    floor_yhat = max(1.0, ref * 0.15)
    floor_lo = max(0.5, floor_yhat * 0.4)

    out = fc.copy()
    out["yhat"] = np.maximum(out["yhat"], floor_yhat)
    out["yhat_lower"] = np.maximum(out["yhat_lower"], floor_lo)
    out["yhat_lower"] = np.minimum(out["yhat_lower"], out["yhat"])
    out["yhat_upper"] = np.maximum(out["yhat_upper"], out["yhat"])
    return out


def prophet_predict(df: pd.DataFrame, periods: int) -> Tuple[pd.DataFrame, str]:
    """Prophet only. Raises on failure."""
    if len(df) < 2:
        raise ValueError("Need at least 2 days in the training window for Prophet.")

    y = df["y"].astype(float)
    if float(y.sum()) < 1e-6:
        raise ValueError("All historical bag totals are zero; add sales data before forecasting.")

    from prophet import Prophet

    n = len(df)
    weekly_on = n >= 14
    yearly_on = n >= 730
    # Additive avoids multiplicative collapse toward zero when levels vary.
    m = Prophet(
        daily_seasonality=n >= 14,
        weekly_seasonality=weekly_on,
        yearly_seasonality=yearly_on,
        seasonality_mode="additive",
        changepoint_prior_scale=0.05,
        interval_width=0.85,
    )
    m.fit(df)
    fut = m.make_future_dataframe(periods=periods, freq="D", include_history=False)
    fc = m.predict(fut)
    tail = fc[["ds", "yhat", "yhat_lower", "yhat_upper"]].copy()
    tail["yhat"] = tail["yhat"].clip(lower=0.0)
    tail["yhat_lower"] = tail["yhat_lower"].clip(lower=0.0)
    tail["yhat_upper"] = tail["yhat_upper"].clip(lower=0.0)
    tail = apply_business_floor(tail, df["y"])
    return tail, "prophet"


def point_from_fc(fc: pd.DataFrame) -> Dict[str, float]:
    if fc is None or fc.empty:
        return {"yhat": 0.0, "yhat_low": 0.0, "yhat_high": 0.0}
    r = fc.iloc[0]
    return {
        "yhat": round(float(r["yhat"]), 2),
        "yhat_low": round(float(r["yhat_lower"]), 2),
        "yhat_high": round(float(r["yhat_upper"]), 2),
    }


def weekly_monthly_from_daily(
    hist_df: pd.DataFrame, fc_df: pd.DataFrame, horizon_w: int, horizon_m: int
) -> Tuple[dict, dict]:
    s = hist_df.set_index("ds")["y"]
    w = s.resample("W-MON", label="left", closed="left").sum()
    weekly_hist = [{"period_start": i.strftime("%Y-%m-%d"), "bags": round(float(v), 2)} for i, v in w.items()]
    m = s.resample("MS").sum()
    monthly_hist = [{"month": i.strftime("%Y-%m"), "bags": round(float(v), 2)} for i, v in m.items()]

    fc = fc_df.set_index("ds") if len(fc_df) else pd.DataFrame(columns=["yhat"]).set_index(pd.DatetimeIndex([]))

    last_w = w.index.max() if len(w) else hist_df["ds"].max()
    weekly_fc = []
    for i in range(horizon_w):
        start = last_w + pd.Timedelta(weeks=i + 1)
        end = start + pd.Timedelta(days=6)
        if len(fc):
            mask = (fc.index >= start) & (fc.index <= end)
            pt = float(fc.loc[mask, "yhat"].sum()) if mask.any() else 0.0
            lo = float(fc.loc[mask, "yhat_lower"].sum()) if mask.any() else 0.0
            hi = float(fc.loc[mask, "yhat_upper"].sum()) if mask.any() else 0.0
        else:
            pt = lo = hi = 0.0
        weekly_fc.append(
            {
                "period_start": start.strftime("%Y-%m-%d"),
                "yhat": round(pt, 2),
                "yhat_low": round(max(0.0, lo), 2),
                "yhat_high": round(max(0.0, hi), 2),
            }
        )

    last_hist = hist_df["ds"].max()
    last_m = pd.Timestamp(last_hist).to_period("M").to_timestamp()
    monthly_fc = []
    for i in range(horizon_m):
        month_start = last_m + pd.DateOffset(months=i + 1)
        month_end = month_start + pd.DateOffset(months=1) - pd.Timedelta(days=1)
        if len(fc):
            mask = (fc.index >= month_start) & (fc.index <= month_end)
            pt = float(fc.loc[mask, "yhat"].sum()) if mask.any() else 0.0
            lo = float(fc.loc[mask, "yhat_lower"].sum()) if mask.any() else 0.0
            hi = float(fc.loc[mask, "yhat_upper"].sum()) if mask.any() else 0.0
        else:
            pt = lo = hi = 0.0
        monthly_fc.append(
            {
                "month": month_start.strftime("%Y-%m"),
                "yhat": round(pt, 2),
                "yhat_low": round(max(0.0, lo), 2),
                "yhat_high": round(max(0.0, hi), 2),
            }
        )

    return (
        {"history": weekly_hist, "forecast": weekly_fc},
        {"history": monthly_hist, "forecast": monthly_fc},
    )


def run(payload: dict) -> dict:
    daily_rows = payload.get("daily") or []
    horizon_w = int(payload.get("horizon_weeks", 4))
    horizon_m = int(payload.get("horizon_months", 3))
    horizon_days = int(payload.get("horizon_days", 14))
    horizon_w = max(1, min(horizon_w, 12))
    horizon_m = max(1, min(horizon_m, 12))
    horizon_days = max(1, min(horizon_days, 90))

    if not daily_rows:
        return {"success": False, "error": "No daily rows."}

    norm: List[Dict[str, Any]] = []
    for r in daily_rows:
        norm.append(
            {
                "date": r.get("date"),
                "total_bags": _safe_float(r.get("total_bags")),
                "revenue": _safe_float(r.get("revenue")),
            }
        )

    df_total = rows_to_daily_df(norm, "total_bags")
    if df_total.empty:
        return {"success": False, "error": "No usable dates in training data."}

    try:
        fc_total, m_total = prophet_predict(df_total, horizon_days)
    except Exception as e:
        return {"success": False, "error": str(e)}

    total_rev = sum(r["revenue"] for r in norm)
    total_bags_hist = float(df_total["y"].sum())
    avg_price = (total_rev / total_bags_hist) if total_bags_hist > 1e-6 else 0.0

    p_total = point_from_fc(fc_total)

    fd = fc_total.iloc[0]["ds"] if len(fc_total) else df_total["ds"].max() + pd.Timedelta(days=1)
    forecast_date = pd.Timestamp(fd).strftime("%Y-%m-%d")

    def rev_est(p: Dict[str, float]) -> Dict[str, float]:
        return {
            "yhat": round(p["yhat"] * avg_price, 2),
            "yhat_low": round(p["yhat_low"] * avg_price, 2),
            "yhat_high": round(p["yhat_high"] * avg_price, 2),
        }

    est = rev_est(p_total)

    by_d: Dict[str, Dict[str, Any]] = {}
    for r in norm:
        ds = str(r.get("date", ""))[:10]
        if len(ds) == 10:
            by_d[ds] = r

    daily_chart = []
    for _, row in df_total.iterrows():
        d = row["ds"].strftime("%Y-%m-%d")
        mrow = by_d.get(d, {})
        daily_chart.append(
            {
                "date": d,
                "total_bags": round(float(row["y"]), 2),
                "revenue": round(_safe_float(mrow.get("revenue")), 2),
            }
        )

    daily_forecast = []
    for i in range(len(fc_total)):
        r = fc_total.iloc[i]
        pt = float(r["yhat"])
        lo = float(r["yhat_lower"])
        hi = float(r["yhat_upper"])
        daily_forecast.append(
            {
                "date": r["ds"].strftime("%Y-%m-%d"),
                "total_bags": {
                    "yhat": round(pt, 2),
                    "yhat_low": round(lo, 2),
                    "yhat_high": round(hi, 2),
                },
                "estimated_revenue": {
                    "yhat": round(pt * avg_price, 2),
                    "yhat_low": round(lo * avg_price, 2),
                    "yhat_high": round(hi * avg_price, 2),
                },
            }
        )

    weekly, monthly = weekly_monthly_from_daily(df_total, fc_total, horizon_w, horizon_m)

    ref_bags = _reference_demand(df_total["y"])
    notes = [
        "Forecast: Facebook Prophet (additive) on total bags/day; PHP fallback is disabled server-side.",
        "Expected revenue = forecast bags × average ₱/bag in the training window.",
        f"Model: {m_total}. When history shows steady demand, point forecasts are floored (min ~15% of recent typical volume, at least 1 bag) so the series does not snap to zero.",
    ]
    if ref_bags > 0:
        notes.append(f"Reference demand for floor ≈ {round(ref_bags, 1)} bags/day (from recent/median history).")
    if len(df_total) < 14:
        notes.append("Short history: use a longer training window for more stable seasonality.")

    return {
        "success": True,
        "meta": {
            "training_days": int(len(df_total)),
            "weekly_observations": len(weekly["history"]),
            "monthly_observations": len(monthly["history"]),
            "weekly_method": "prophet_daily_aggregated",
            "monthly_method": "prophet_daily_aggregated",
            "engine": "prophet",
            "avg_price_per_bag": round(avg_price, 4),
            "horizon_days": horizon_days,
            "models": {"total_bags": m_total},
            "demand_reference_bags_per_day": round(ref_bags, 2),
        },
        "headline": {
            "forecast_date": forecast_date,
            "total_bags": p_total,
            "estimated_revenue": est,
        },
        "notes": notes,
        "daily": daily_chart,
        "daily_forecast": daily_forecast,
        "weekly": weekly,
        "monthly": monthly,
    }


def main() -> None:
    try:
        raw = sys.stdin.read()
        payload = json.loads(raw) if raw.strip() else {}
        out = run(payload)
    except Exception as e:
        out = {"success": False, "error": str(e)}
    # Windows consoles often use cp1252; escape non-ASCII so PHP always gets valid JSON.
    sys.stdout.write(json.dumps(out, ensure_ascii=True))


if __name__ == "__main__":
    main()
