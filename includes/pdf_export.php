<?php
/**
 * Export des listes en PDF (A4 paysage) aux couleurs de la CCAD.
 * Repose sur FPDF 1.86 (includes/lib/fpdf, licence libre).
 */
require_once __DIR__ . '/lib/fpdf/fpdf.php';

class CcadPdf extends FPDF
{
    public string $titre = '';
    public string $sousTitre = '';
    public string $auteur = '';
    private array $colonnes = [];
    private array $largeurs = [];
    private array $alignements = [];

    /** Texte UTF-8 → Windows-1252 (jeu de caractères des polices standard du PDF). */
    public static function t($s): string
    {
        $s = strtr((string)$s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '→' => '->', '−' => '-']);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $r === false ? mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : $r;
    }

    public function Header(): void
    {
        $logo = __DIR__ . '/../assets/img/logo-ccad.jpg';
        if (is_file($logo)) $this->Image($logo, 10, 8, 16, 16);
        $this->SetXY(30, 8);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetTextColor(18, 39, 110);
        $this->Cell(0, 6, self::t(setting('org_nom', 'CCAD')), 0, 2);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(86, 94, 114);
        $this->Cell(0, 4, self::t(setting('org_adresse') . ' · ' . setting('org_tel') . ' · NIF ' . setting('org_nif')), 0, 2);
        $this->SetXY(30, 19);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(20, 24, 33);
        $this->Cell(150, 5, self::t($this->titre));
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(86, 94, 114);
        $this->Cell(0, 5, self::t($this->sousTitre), 0, 0, 'R');
        // Filet abricot sous l'en-tête
        $this->SetDrawColor(232, 149, 15);
        $this->SetLineWidth(0.8);
        $this->Line(10, 27, $this->GetPageWidth() - 10, 27);
        $this->SetLineWidth(0.2);
        $this->SetY(31);
        if ($this->colonnes) $this->enteteTableau();
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(224, 228, 237);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(122, 130, 150);
        $this->Cell(0, 6, self::t('Document confidentiel · généré par ' . $this->auteur . ' le ' . date('d/m/Y à H:i') . ' · CCAD Gestion v' . APP_VERSION));
        $this->SetX(10);
        $this->Cell(0, 6, self::t('Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'R');
    }

    private function enteteTableau(): void
    {
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetFillColor(18, 39, 110);
        $this->SetTextColor(255, 255, 255);
        foreach ($this->colonnes as $i => $c) {
            $this->Cell($this->largeurs[$i], 7, $this->couper(self::t(mb_strtoupper($c)), $this->largeurs[$i]), 0, 0, $this->alignements[$i], true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(36, 42, 56);
    }

    /** Coupe un texte trop long pour sa colonne, avec points de suspension. */
    private function couper(string $s, float $w): string
    {
        $max = $w - 2.4;
        if ($this->GetStringWidth($s) <= $max) return $s;
        while ($s !== '' && $this->GetStringWidth($s . "\x85") > $max) $s = substr($s, 0, -1);
        return $s . "\x85";
    }

    /**
     * Tableau paginé : largeurs calculées sur le contenu puis ajustées à la page.
     * $droite : colonnes alignées à droite (montants, nombres).
     */
    public function tableau(array $colonnes, array $lignes, array $droite = [], array $totaux = []): void
    {
        $this->colonnes = $colonnes;
        $this->SetFont('Helvetica', '', 8);
        $dispo = $this->GetPageWidth() - 20;
        $souhaits = [];
        foreach ($colonnes as $i => $c) {
            $this->SetFont('Helvetica', 'B', 7.5);
            $w = $this->GetStringWidth(self::t(mb_strtoupper($c))) + 4;
            $this->SetFont('Helvetica', '', 8);
            foreach (array_slice($lignes, 0, 400) as $l) $w = max($w, $this->GetStringWidth(self::t($l[$i])) + 4);
            $souhaits[$i] = min($w, 70);
        }
        $total = array_sum($souhaits) ?: 1;
        foreach ($souhaits as $i => $w) $this->largeurs[$i] = $w * $dispo / $total;
        foreach ($colonnes as $i => $c) $this->alignements[$i] = in_array($c, $droite, true) ? 'R' : 'L';

        $this->AliasNbPages();
        $this->AddPage();
        $this->SetDrawColor(224, 228, 237);
        foreach ($lignes as $n => $l) {
            if ($this->GetY() > $this->GetPageHeight() - 20) $this->AddPage();
            $zebre = $n % 2 === 1;
            $this->SetFillColor(247, 248, 251);
            foreach ($colonnes as $i => $c) {
                $this->Cell($this->largeurs[$i], 6, $this->couper(self::t($l[$i]), $this->largeurs[$i]), 'B', 0, $this->alignements[$i], $zebre);
            }
            $this->Ln();
        }
        if (!$lignes) {
            $this->SetTextColor(122, 130, 150);
            $this->Cell($dispo, 12, self::t('Aucune donnée pour cette sélection.'), 0, 1, 'C');
        }
        if ($totaux) {
            if ($this->GetY() > $this->GetPageHeight() - 24) $this->AddPage();
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetFillColor(253, 236, 203);
            foreach ($colonnes as $i => $c) {
                $txt = $i === 0 ? 'TOTAL (' . count($lignes) . ')' : ($totaux[$c] ?? '');
                $this->Cell($this->largeurs[$i], 7, $this->couper(self::t($txt), $this->largeurs[$i]), 'T', 0, $this->alignements[$i], true);
            }
            $this->Ln();
        }
        $this->colonnes = [];
    }
}
