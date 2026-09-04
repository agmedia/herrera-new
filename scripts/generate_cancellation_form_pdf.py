from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "public" / "documents" / "obrazac-za-jednostrani-raskid-ugovora.pdf"
FONT_PATH = Path("/System/Library/Fonts/Supplemental/Arial.ttf")
FONT_BOLD_PATH = Path("/System/Library/Fonts/Supplemental/Arial Bold.ttf")


def field_row(label: str, height: float = 12 * mm) -> list[str]:
    return [label, ""]


def build() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)

    pdfmetrics.registerFont(TTFont("Arial", str(FONT_PATH)))
    pdfmetrics.registerFont(TTFont("Arial-Bold", str(FONT_BOLD_PATH)))

    document = SimpleDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        rightMargin=20 * mm,
        leftMargin=20 * mm,
        topMargin=18 * mm,
        bottomMargin=18 * mm,
        title="Obrazac za jednostrani raskid ugovora",
        author="Bali d.o.o.",
    )

    styles = getSampleStyleSheet()
    title = ParagraphStyle(
        "TitleArial",
        parent=styles["Title"],
        fontName="Arial-Bold",
        fontSize=16,
        leading=20,
        alignment=TA_CENTER,
        spaceAfter=8 * mm,
    )
    body = ParagraphStyle(
        "BodyArial",
        parent=styles["BodyText"],
        fontName="Arial",
        fontSize=10.5,
        leading=15,
        textColor=colors.HexColor("#111827"),
    )
    small = ParagraphStyle(
        "SmallArial",
        parent=body,
        fontSize=8.5,
        leading=12,
        textColor=colors.HexColor("#4B5563"),
    )

    story = [
        Paragraph("OBRAZAC ZA JEDNOSTRANI RASKID UGOVORA", title),
        Paragraph(
            "Ispunjeni obrazac pošaljite poštom ili e-mailom na sljedeću adresu:",
            body,
        ),
        Spacer(1, 2 * mm),
        Paragraph(
            "<b>Bali d.o.o.</b><br/>Hrupine 19, 40323 Prelog, Hrvatska<br/>"
            "E-mail: webshop@balidoo.com",
            body,
        ),
        Spacer(1, 7 * mm),
        Paragraph(
            "Ovime izjavljujem da jednostrano raskidam ugovor o prodaji sljedeće robe "
            "odnosno ugovor o pružanju sljedeće usluge:",
            body,
        ),
        Spacer(1, 3 * mm),
    ]

    fields = Table(
        [
            field_row("Naziv proizvoda / usluge", 24 * mm),
            field_row("Broj narudžbe"),
            field_row("Naručeno dana"),
            field_row("Primljeno dana"),
            field_row("Ime i prezime potrošača"),
            field_row("Adresa potrošača", 18 * mm),
            field_row("E-mail / telefon"),
        ],
        colWidths=[58 * mm, 112 * mm],
        rowHeights=[24 * mm, 12 * mm, 12 * mm, 12 * mm, 12 * mm, 18 * mm, 12 * mm],
    )
    fields.setStyle(
        TableStyle(
            [
                ("FONTNAME", (0, 0), (-1, -1), "Arial"),
                ("FONTSIZE", (0, 0), (-1, -1), 9.5),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#F3F4F6")),
                ("GRID", (0, 0), (-1, -1), 0.6, colors.HexColor("#9CA3AF")),
                ("LEFTPADDING", (0, 0), (-1, -1), 7),
                ("RIGHTPADDING", (0, 0), (-1, -1), 7),
            ]
        )
    )
    story.extend([fields, Spacer(1, 8 * mm)])

    signature = Table(
        [["Mjesto i datum", "Potpis potrošača"]],
        colWidths=[82 * mm, 82 * mm],
        rowHeights=[20 * mm],
    )
    signature.setStyle(
        TableStyle(
            [
                ("FONTNAME", (0, 0), (-1, -1), "Arial"),
                ("FONTSIZE", (0, 0), (-1, -1), 9.5),
                ("VALIGN", (0, 0), (-1, -1), "BOTTOM"),
                ("LINEABOVE", (0, 0), (-1, -1), 0.7, colors.HexColor("#6B7280")),
                ("LEFTPADDING", (0, 0), (-1, -1), 0),
                ("RIGHTPADDING", (0, 0), (-1, -1), 0),
            ]
        )
    )
    story.extend(
        [
            signature,
            Spacer(1, 4 * mm),
            Paragraph("Potpis je potreban samo ako se obrazac ispunjava na papiru.", small),
        ]
    )

    document.build(story)


if __name__ == "__main__":
    build()
