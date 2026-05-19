<?php

declare(strict_types=1);

namespace Tourneo\Service;

class PrintService
{
    public function generateRouteHtml(array $route, array $config = []): string
    {
        $truck  = $route['truck']  ?? [];
        $agency = $route['agency'] ?? [];
        $points = $route['points'] ?? [];

        $truckId   = $truck['id']                   ?? 'N/A';
        $driver    = $truck['chauffeur']             ?? '';
        $tel       = $truck['tel_chauffeur']         ?? '';
        $timeStart = $truck['heure_debut_chauffeur'] ?? '';
        $timeEnd   = $truck['heure_fin_chauffeur']   ?? '';
        $depotName = $agency['id_nom']               ?? '';
        $depotAddr = trim(($agency['adresse'] ?? '') . ', ' . ($agency['ville'] ?? ''));

        $distance  = number_format((float)($route['distance']  ?? 0), 1, ',', ' ');
        $duration  = $this->formatDuration((float)($route['duration']  ?? 0));
        $fuelCost  = number_format((float)($route['fuelCost']  ?? 0), 2, ',', ' ');
        $laborCost = number_format((float)($route['laborCost'] ?? 0), 2, ',', ' ');
        $totalCost = number_format((float)($route['totalCost'] ?? 0), 2, ',', ' ');

        $date    = date('d/m/Y');
        $dayName = $this->frenchDayName((int) date('N'));
        $docId   = 'BL-' . strtoupper(substr(md5($truckId . date('Ymd') . ($agency['id_nom'] ?? '')), 0, 6));

        $realPoints = array_filter($points, fn($p) => empty($p['is_break']));
        $stopCount  = count($realPoints);
        $totalVol   = number_format((float)($route['totalVolume'] ?? 0), 1, ',', ' ');

        $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // ── Lignes du tableau ───────────────────────────────────────────
        $tbody = '';
        $n = 1;
        foreach ($points as $p) {
            if (!empty($p['is_break'])) {
                $tbody .= '<tr><td colspan="6" style="background:#fefce8;border-top:2px dashed #f59e0b;border-bottom:2px dashed #f59e0b;color:#92400e;text-align:center;font-family:\'IBM Plex Mono\',monospace;font-weight:500;font-size:10px;padding:8px;letter-spacing:.06em">⏸ PAUSE RÉGLEMENTAIRE 45 MIN — EU 561/2006</td></tr>';
                continue;
            }

            $prio    = (int)($p['priorite'] ?? 2);
            $badge   = $prio === 1
                ? '<span style="display:inline-block;background:#fee2e2;color:#991b1b;font-family:\'IBM Plex Mono\',monospace;font-size:9px;font-weight:600;padding:1px 6px;border-radius:3px;margin-right:5px;letter-spacing:.04em;vertical-align:middle">URGENT</span>'
                : ($prio === 3
                    ? '<span style="display:inline-block;background:#dcfce7;color:#166534;font-family:\'IBM Plex Mono\',monospace;font-size:9px;font-weight:600;padding:1px 6px;border-radius:3px;margin-right:5px;letter-spacing:.04em;vertical-align:middle">FLEX</span>'
                    : '');

            $arrival = isset($p['arrival_min']) ? $this->formatTime((int)$p['arrival_min']) : '—';
            $tw      = '';
            if (isset($p['tw_start']) || isset($p['tw_end'])) {
                $ts = isset($p['tw_start']) ? $this->formatTime((int)$p['tw_start']) : '';
                $te = isset($p['tw_end'])   ? $this->formatTime((int)$p['tw_end'])   : '';
                $tw = '&nbsp;<span style="display:inline-block;background:#fef9c3;color:#854d0e;font-family:\'IBM Plex Mono\',monospace;font-size:9px;font-weight:500;padding:1px 5px;border-radius:3px;vertical-align:middle">' . $ts . '–' . $te . '</span>';
            }

            $bg  = $n % 2 === 0 ? '#f8fafc' : '#ffffff';
            $vol = number_format((float)($p['volume'] ?? 0), 1, ',', ' ');

            $tbody .= '<tr style="background:' . $bg . '">'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:center;font-family:\'IBM Plex Mono\',monospace;color:#94a3b8;font-size:11px">' . $n . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;font-weight:700">' . $badge . $e($p['nom_client'] ?? '') . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:12px">' . $e($p['adresse'] ?? '') . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:12px;white-space:nowrap">' . $e($p['ville'] ?? '') . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:\'IBM Plex Mono\',monospace;font-size:11px;color:#475569">' . $vol . ' m³</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:center;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500;white-space:nowrap">' . $arrival . $tw . '</td>'
                . '</tr>';
            $n++;
        }

        // ── Rangée dépôt retour ─────────────────────────────────────────
        $tbody .= '<tr style="background:#fefce8">'
            . '<td style="padding:9px 10px;text-align:center;font-family:\'IBM Plex Mono\',monospace;color:#f59e0b;font-size:14px">↩</td>'
            . '<td colspan="4" style="padding:9px 10px;font-family:\'Syne\',sans-serif;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em">Retour au dépôt — ' . $e($depotName) . '</td>'
            . '<td style="padding:9px 10px"></td>'
            . '</tr>';

        // ── Infos conducteur ────────────────────────────────────────────
        $driverVal  = $driver    !== '' ? $e($driver)   : '<span style="color:#cbd5e1;font-style:italic">Non renseigné</span>';
        $telVal     = $tel       !== '' ? '<a href="tel:' . $e($tel) . '" style="color:#f59e0b;text-decoration:none;font-family:\'IBM Plex Mono\',monospace">' . $e($tel) . '</a>' : '<span style="color:#cbd5e1">—</span>';
        $hoursVal   = ($timeStart !== '' || $timeEnd !== '') ? $e($timeStart) . ' → ' . $e($timeEnd) : '<span style="color:#cbd5e1">—</span>';

        return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $e($docId) . ' — ' . $e($truckId) . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: "Syne", sans-serif; font-size: 13px; color: #1a1f2e; background: #fff; padding: 36px 44px; max-width: 1060px; margin: 0 auto; line-height: 1.5; }
@media print { body { padding: 14px 18px; } }
</style>
</head>
<body>

<!-- ══════════════════════════════ EN-TÊTE ══════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;background:#0c0e12;border-radius:10px;overflow:hidden;margin-bottom:0">
  <tr>
    <td style="padding:18px 24px;vertical-align:middle">
      <table style="border-collapse:collapse">
        <tr>
          <td style="vertical-align:middle;padding-right:14px">
            <div style="width:48px;height:48px;background:#f59e0b;border-radius:8px;text-align:center;line-height:48px;font-family:\'Syne\',sans-serif;font-size:20px;font-weight:800;color:#0c0e12;letter-spacing:-1px">T°</div>
          </td>
          <td style="vertical-align:middle">
            <div style="font-family:\'Syne\',sans-serif;font-size:22px;font-weight:800;color:#d4dce8;letter-spacing:-.5px">TOURNÉ<span style="color:#f59e0b">O</span></div>
            <div style="font-family:\'IBM Plex Mono\',monospace;font-size:10px;color:#4d6680;margin-top:2px;letter-spacing:.05em">// planification de tournées</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="padding:18px 24px;vertical-align:middle;text-align:right">
      <div style="font-family:\'IBM Plex Mono\',monospace;font-size:10px;color:#4d6680;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px">Bon de livraison</div>
      <div style="font-family:\'IBM Plex Mono\',monospace;font-size:24px;font-weight:600;color:#f59e0b;letter-spacing:.08em">' . $e($docId) . '</div>
      <div style="font-family:\'IBM Plex Mono\',monospace;font-size:11px;color:#4d6680;margin-top:4px">' . $e($dayName) . ' ' . $e($date) . '</div>
    </td>
  </tr>
</table>
<div style="height:3px;background:linear-gradient(to right,#f59e0b,#fbbf24 60%,transparent);margin-bottom:24px"></div>

<!-- ══════════════════════════════ 3 BLOCS INFO ═════════════════════════ -->
<table style="width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:22px">
  <tr>

    <!-- Véhicule & Conducteur -->
    <td style="width:33%;vertical-align:top;border:1px solid #e8ecf2;border-radius:8px;overflow:hidden;padding:0">
      <div style="background:#0c0e12;padding:7px 14px">
        <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">01 —</span>
        <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Véhicule &amp; Conducteur</span>
      </div>
      <div style="padding:10px 14px">
        <table style="width:100%;border-collapse:collapse">
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;width:76px;border-bottom:1px solid #f1f5f9">VÉHICULE</td><td style="padding:5px 0;font-size:12px;font-weight:700;color:#1a1f2e;border-bottom:1px solid #f1f5f9">' . $e($truckId) . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;border-bottom:1px solid #f1f5f9">CONDUCTEUR</td><td style="padding:5px 0;font-size:12px;font-weight:600;color:#1a1f2e;border-bottom:1px solid #f1f5f9">' . $driverVal . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;border-bottom:1px solid #f1f5f9">TÉL.</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500;border-bottom:1px solid #f1f5f9">' . $telVal . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em">AMPLITUDE</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500;color:#1a1f2e">' . $hoursVal . '</td></tr>
        </table>
      </div>
    </td>

    <!-- Dépôt -->
    <td style="width:33%;vertical-align:top;border:1px solid #e8ecf2;border-radius:8px;overflow:hidden;padding:0">
      <div style="background:#0c0e12;padding:7px 14px">
        <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">02 —</span>
        <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Dépôt départ &amp; retour</span>
      </div>
      <div style="padding:10px 14px">
        <table style="width:100%;border-collapse:collapse">
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;width:76px;border-bottom:1px solid #f1f5f9">NOM</td><td style="padding:5px 0;font-size:12px;font-weight:700;color:#1a1f2e;border-bottom:1px solid #f1f5f9">' . $e($depotName) . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;border-bottom:1px solid #f1f5f9">ADRESSE</td><td style="padding:5px 0;font-size:12px;font-weight:600;color:#1a1f2e;border-bottom:1px solid #f1f5f9">' . $e($depotAddr) . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em">RETOUR</td><td style="padding:5px 0;font-size:12px;font-weight:600;color:#94a3b8">Même dépôt</td></tr>
        </table>
      </div>
    </td>

    <!-- Résumé -->
    <td style="width:33%;vertical-align:top;border:1px solid #e8ecf2;border-radius:8px;overflow:hidden;padding:0">
      <div style="background:#0c0e12;padding:7px 14px">
        <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">03 —</span>
        <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Résumé de la tournée</span>
      </div>
      <div style="padding:10px 14px">
        <table style="width:100%;border-collapse:collapse">
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;width:76px;border-bottom:1px solid #f1f5f9">ARRÊTS</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:13px;font-weight:600;color:#f59e0b;border-bottom:1px solid #f1f5f9">' . $stopCount . '</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;border-bottom:1px solid #f1f5f9">VOLUME</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500;border-bottom:1px solid #f1f5f9">' . $totalVol . ' m³</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em;border-bottom:1px solid #f1f5f9">DISTANCE</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500;border-bottom:1px solid #f1f5f9">' . $distance . ' km</td></tr>
          <tr><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.04em">DURÉE EST.</td><td style="padding:5px 0;font-family:\'IBM Plex Mono\',monospace;font-size:11px;font-weight:500">' . $duration . '</td></tr>
        </table>
      </div>
    </td>

  </tr>
</table>

<!-- ══════════════════════════════ TABLEAU ══════════════════════════════ -->
<div style="margin-bottom:0">
  <div style="background:#0c0e12;border-radius:8px 8px 0 0;padding:8px 14px;display:inline-block;margin-bottom:0">
    <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">04 —</span>
    <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Détail des livraisons</span>
  </div>
</div>
<div style="margin-bottom:22px;border:1px solid #0c0e12;border-radius:0 8px 8px 8px;overflow:hidden">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="background:#111318">
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:center;width:34px">#</th>
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:left;width:22%">Client</th>
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:left;width:26%">Adresse</th>
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:left;width:14%">Ville</th>
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:right;width:9%">Vol.</th>
        <th style="padding:9px 10px;font-family:\'IBM Plex Mono\',monospace;color:#4d6680;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;text-align:center;width:14%">Heure prévue</th>
      </tr>
    </thead>
    <tbody>' . $tbody . '</tbody>
  </table>
</div>

<!-- ══════════════════════════════ BAS DE PAGE ══════════════════════════ -->
<table style="width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:28px">
  <tr>

    <!-- Coûts -->
    <td style="width:38%;vertical-align:top;border:1px solid #e8ecf2;border-radius:8px;overflow:hidden;padding:0">
      <div style="background:#0c0e12;padding:7px 14px">
        <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">05 —</span>
        <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Coûts estimés</span>
      </div>
      <div style="padding:8px 14px">
        <table style="width:100%;border-collapse:collapse">
          <tr><td style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px;color:#64748b">Carburant</td><td style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-family:\'IBM Plex Mono\',monospace;font-size:12px;font-weight:500;text-align:right">' . $fuelCost . ' €</td></tr>
          <tr><td style="padding:6px 0;font-size:12px;color:#64748b">Main d\'œuvre</td><td style="padding:6px 0;font-family:\'IBM Plex Mono\',monospace;font-size:12px;font-weight:500;text-align:right">' . $laborCost . ' €</td></tr>
        </table>
      </div>
      <div style="background:#111318;padding:10px 14px">
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="font-family:\'Syne\',sans-serif;font-size:12px;font-weight:700;color:#d4dce8">Coût total</td>
            <td style="font-family:\'IBM Plex Mono\',monospace;font-size:18px;font-weight:600;color:#f59e0b;text-align:right">' . $totalCost . ' €</td>
          </tr>
        </table>
      </div>
    </td>

    <!-- Signatures -->
    <td style="width:62%;vertical-align:top;border:1px solid #e8ecf2;border-radius:8px;overflow:hidden;padding:0">
      <div style="background:#0c0e12;padding:7px 14px">
        <span style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#4d6680;letter-spacing:.06em">06 —</span>
        <span style="font-family:\'Syne\',sans-serif;font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.06em;margin-left:4px">Signatures</span>
      </div>
      <table style="width:100%;border-collapse:collapse;height:90px">
        <tr>
          <td style="width:50%;padding:12px 16px;vertical-align:top;border-right:1px solid #e8ecf2">
            <div style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.06em;margin-bottom:6px">CONDUCTEUR</div>
            <div style="font-family:\'Syne\',sans-serif;font-size:13px;font-weight:700;color:#1a1f2e;margin-bottom:22px">' . $e($driver ?: $truckId) . '</div>
            <div style="border-bottom:1.5px solid #cbd5e1"></div>
          </td>
          <td style="width:50%;padding:12px 16px;vertical-align:top">
            <div style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;letter-spacing:.06em;margin-bottom:6px">RESPONSABLE EXPLOITATION</div>
            <div style="margin-bottom:22px">&nbsp;</div>
            <div style="border-bottom:1.5px solid #cbd5e1"></div>
          </td>
        </tr>
      </table>
    </td>

  </tr>
</table>

<!-- ══════════════════════════════ FOOTER ═══════════════════════════════ -->
<div style="height:1px;background:#e8ecf2;margin-bottom:10px"></div>
<table style="width:100%;border-collapse:collapse">
  <tr>
    <td style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8"><span style="font-weight:600;color:#f59e0b">TOURNÉO</span> — Généré le ' . $e($date) . ' — Réf. ' . $e($docId) . '</td>
    <td style="font-family:\'IBM Plex Mono\',monospace;font-size:9px;color:#94a3b8;text-align:right">Document confidentiel — usage interne uniquement</td>
  </tr>
</table>

</body>
</html>';
    }

    private function formatTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function formatDuration(float $hours): string
    {
        $h   = (int) $hours;
        $min = (int) round(($hours - $h) * 60);
        return sprintf('%dh%02d', $h, $min);
    }

    private function frenchDayName(int $isoDay): string
    {
        return match($isoDay) {
            1 => 'Lundi', 2 => 'Mardi',  3 => 'Mercredi',
            4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi',
            default => 'Dimanche',
        };
    }
}
