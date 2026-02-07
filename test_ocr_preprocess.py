import os
import mysql.connector
import dotenv
import pytesseract
from PIL import Image

dotenv.load_dotenv('.env')

# Re-implementing preprocess_image briefly for standalone test
def preprocess_image(img):
    img = img.convert('L')
    w, h = img.size
    img = img.resize((w * 2, h * 2), Image.Resampling.LANCZOS)
    img = img.point(lambda p: 255 if p > 128 else 0)
    return img

conn = mysql.connector.connect(
    host=os.getenv('DB_HOST'),
    user=os.getenv('DB_USERNAME'),
    password=os.getenv('DB_PASSWORD'),
    database=os.getenv('DB_NAME')
)

cursor = conn.cursor(dictionary=True)
doc_id = 10553
local_path = 'storage/manual_uploads/photos/IMAGES/012/HOUSE_OVERSIGHT_033453.jpg'
full_path = os.path.join(os.getcwd(), local_path)

if not os.path.exists(full_path):
    print(f"Error: File not found at {full_path}")
    sys.exit(1)

print(f"Opening {full_path}...")
img = Image.open(full_path)
processed_img = preprocess_image(img)

print("Starting OCR...")
text = pytesseract.image_to_string(processed_img)

print(f"OCR Text Length: {len(text)}")
print(f"Snippet: {text[:200]}")

# Save to DB so we can try generate summary later
cursor.execute("""
    INSERT INTO pages (document_id, page_number, ocr_text) 
    VALUES (%s, %s, %s) 
    ON DUPLICATE KEY UPDATE ocr_text = VALUES(ocr_text)
""", (doc_id, 1, text))

conn.commit()
conn.close()
print("Success. Saved to DB.")
