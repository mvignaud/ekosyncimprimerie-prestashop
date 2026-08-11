#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Produit le logo du module en PNG, sans dépendance externe.

Le rendu passe par un suréchantillonnage 4x puis une moyenne : les bords du
cercle et du carré arrondi sortent lissés, ce qu'un tracé pixel par pixel ne
donnerait pas. zlib et struct suffisent à écrire un PNG valide.
"""
import math
import struct
import sys
import zlib

FOND = (0x2A, 0x6D, 0xAF)
TRAIT = (0xFF, 0xFF, 0xFF)

# Géométrie, exprimée dans un carré de référence de 256 points.
COTE = 256.0
RAYON_COIN = 42.0
CX = CY = 128.0
R_EXT = 88.0
R_INT = 52.0
BARRE_Y = 118.0          # hauteur de la barre horizontale du « e »
BARRE_EP = 13.0          # demi-épaisseur
OUVERTURE = (-58.0, 4.0)  # secteur retiré, en degrés, ouvrant la bouche


def dans_carre_arrondi(x, y):
    """Le point est-il dans le carré aux coins arrondis ?"""
    dx = max(RAYON_COIN - x, 0.0, x - (COTE - RAYON_COIN))
    dy = max(RAYON_COIN - y, 0.0, y - (COTE - RAYON_COIN))
    return dx * dx + dy * dy <= RAYON_COIN * RAYON_COIN


def dans_e(x, y):
    """Le point appartient-il au glyphe ?

    Anneau privé d'un secteur (la bouche s'ouvre par là), auquel s'ajoute la
    barre horizontale. La barre ferme l'œil du haut et laisse le bas ouvert.
    """
    dx, dy = x - CX, y - CY
    d = math.hypot(dx, dy)

    if d > R_EXT:
        return False

    # Angle trigonométrique usuel : x vers la droite, y vers le HAUT.
    angle = math.degrees(math.atan2(-dy, dx))

    dans_anneau = d >= R_INT
    if dans_anneau and OUVERTURE[0] <= angle <= OUVERTURE[1]:
        dans_anneau = False

    dans_barre = abs(y - BARRE_Y) <= BARRE_EP

    return dans_anneau or dans_barre


def rendre(taille, sur=4):
    """Rend une image RGBA de `taille` pixels de côté."""
    echelle = COTE / taille
    pas = echelle / sur
    lignes = []

    for py in range(taille):
        ligne = bytearray()
        for px in range(taille):
            couvert_fond = 0
            couvert_trait = 0
            for sy in range(sur):
                y = (py * sur + sy + 0.5) * pas
                for sx in range(sur):
                    x = (px * sur + sx + 0.5) * pas
                    if not dans_carre_arrondi(x, y):
                        continue
                    couvert_fond += 1
                    if dans_e(x, y):
                        couvert_trait += 1

            total = sur * sur
            a = couvert_fond / total
            if a == 0:
                ligne += bytes((0, 0, 0, 0))
                continue

            # Le trait est peint SUR le fond, dans la zone couverte.
            t = couvert_trait / couvert_fond
            r = round(FOND[0] * (1 - t) + TRAIT[0] * t)
            v = round(FOND[1] * (1 - t) + TRAIT[1] * t)
            b = round(FOND[2] * (1 - t) + TRAIT[2] * t)
            ligne += bytes((r, v, b, round(a * 255)))
        lignes.append(bytes(ligne))

    return lignes


def ecrire_png(chemin, lignes, taille):
    brut = b"".join(b"\x00" + l for l in lignes)

    def bloc(nom, donnees):
        c = nom + donnees
        return struct.pack(">I", len(donnees)) + c + struct.pack(">I", zlib.crc32(c) & 0xFFFFFFFF)

    png = b"\x89PNG\r\n\x1a\n"
    png += bloc(b"IHDR", struct.pack(">IIBBBBB", taille, taille, 8, 6, 0, 0, 0))
    png += bloc(b"IDAT", zlib.compress(brut, 9))
    png += bloc(b"IEND", b"")

    with open(chemin, "wb") as f:
        f.write(png)


if __name__ == "__main__":
    for taille in (16, 32, 64, 128, 256):
        nom = "logo-%d.png" % taille
        ecrire_png(nom, rendre(taille), taille)
        print("  %s" % nom)
