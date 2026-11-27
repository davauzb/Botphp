"""O'z ovozingizni klonlash.

1. Telefoningizda 1-3 daqiqa tiniq ovoz yozib oling (shovqinsiz joyda, o'zbekcha gapiring).
2. Ishga tushiring:
       python clone_voice.py "Mening ovozim" namuna1.mp3 namuna2.mp3
3. Chiqqan voice_id ni .env dagi ELEVENLABS_VOICE_ID ga yozing.

Eslatma: ovoz klonlash ElevenLabs'ning pullik tariflarida ishlaydi.
Faqat O'ZINGIZNING ovozingizni (yoki ruxsat bergan odam ovozini) klonlang.
"""
import sys

from core import voice


def main() -> None:
    if len(sys.argv) < 3:
        print(__doc__)
        raise SystemExit(1)
    name, samples = sys.argv[1], sys.argv[2:]
    voice_id = voice.clone_voice(name, samples)
    print(f"Tayyor! voice_id = {voice_id}")
    print(f".env fayliga yozing:  ELEVENLABS_VOICE_ID={voice_id}")


if __name__ == "__main__":
    main()
