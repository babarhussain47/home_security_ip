#!/usr/bin/env python3
"""
classify.py <model.onnx> <image.jpg>

Runs a YOLOv8n ONNX model (CPUExecutionProvider only — no torch/CUDA
dependency) against a single image and prints JSON detection counts to
stdout. Only person/car/animal COCO classes are kept since that's all this
project cares about.

Usage from PHP mirrors how ffmpeg is invoked elsewhere in this repo: shell
out, read stdout, done.
"""

import sys
import json

import numpy as np
from PIL import Image
import onnxruntime as ort

COCO_CLASSES = {
    0: "person",
    2: "car",
    14: "bird",
    15: "cat",
    16: "dog",
    17: "horse",
    18: "sheep",
    19: "cow",
    20: "elephant",
    21: "bear",
    22: "zebra",
    23: "giraffe",
}

CONF_THRESHOLD = 0.4
IOU_THRESHOLD = 0.45
INPUT_SIZE = 640


def letterbox(image, size=INPUT_SIZE):
    w, h = image.size
    scale = size / max(w, h)
    nw, nh = int(w * scale), int(h * scale)
    resized = image.resize((nw, nh), Image.BILINEAR)
    canvas = Image.new("RGB", (size, size), (114, 114, 114))
    pad_x, pad_y = (size - nw) // 2, (size - nh) // 2
    canvas.paste(resized, (pad_x, pad_y))
    return canvas, scale, pad_x, pad_y


def nms(boxes, scores, iou_threshold):
    idxs = scores.argsort()[::-1]
    keep = []
    while idxs.size > 0:
        i = idxs[0]
        keep.append(i)
        if idxs.size == 1:
            break
        rest = idxs[1:]
        xx1 = np.maximum(boxes[i, 0], boxes[rest, 0])
        yy1 = np.maximum(boxes[i, 1], boxes[rest, 1])
        xx2 = np.minimum(boxes[i, 2], boxes[rest, 2])
        yy2 = np.minimum(boxes[i, 3], boxes[rest, 3])
        inter = np.maximum(0, xx2 - xx1) * np.maximum(0, yy2 - yy1)
        area_i = (boxes[i, 2] - boxes[i, 0]) * (boxes[i, 3] - boxes[i, 1])
        area_rest = (boxes[rest, 2] - boxes[rest, 0]) * (boxes[rest, 3] - boxes[rest, 1])
        iou = inter / (area_i + area_rest - inter + 1e-9)
        idxs = rest[iou <= iou_threshold]
    return keep


def main():
    if len(sys.argv) != 3:
        print(json.dumps({"error": "usage: classify.py <model.onnx> <image.jpg>"}), file=sys.stderr)
        sys.exit(1)

    model_path, image_path = sys.argv[1], sys.argv[2]

    image = Image.open(image_path).convert("RGB")
    canvas, scale, pad_x, pad_y = letterbox(image)
    arr = np.asarray(canvas, dtype=np.float32) / 255.0
    arr = arr.transpose(2, 0, 1)[None, ...]

    session = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
    input_name = session.get_inputs()[0].name
    output = session.run(None, {input_name: arr})[0]  # (1, 84, 8400)

    predictions = output[0].T  # (8400, 84): 4 box coords + 80 class scores
    boxes_xywh = predictions[:, :4]
    class_scores = predictions[:, 4:]
    class_ids = class_scores.argmax(axis=1)
    confidences = class_scores.max(axis=1)

    mask = confidences >= CONF_THRESHOLD
    boxes_xywh, class_ids, confidences = boxes_xywh[mask], class_ids[mask], confidences[mask]

    keep_mask = np.isin(class_ids, list(COCO_CLASSES.keys()))
    boxes_xywh, class_ids, confidences = boxes_xywh[keep_mask], class_ids[keep_mask], confidences[keep_mask]

    counts = {}
    detections = []

    if len(boxes_xywh) > 0:
        cx, cy, w, h = boxes_xywh[:, 0], boxes_xywh[:, 1], boxes_xywh[:, 2], boxes_xywh[:, 3]
        boxes_xyxy = np.stack([cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2], axis=1)

        for cls_id in np.unique(class_ids):
            cls_mask = class_ids == cls_id
            keep_idx = nms(boxes_xyxy[cls_mask], confidences[cls_mask], IOU_THRESHOLD)
            cls_boxes = boxes_xyxy[cls_mask][keep_idx]
            cls_scores = confidences[cls_mask][keep_idx]
            name = COCO_CLASSES[int(cls_id)]
            counts[name] = counts.get(name, 0) + len(keep_idx)

            for box, score in zip(cls_boxes, cls_scores):
                detections.append({
                    "class": name,
                    "confidence": round(float(score), 3),
                    "box": [
                        round(max(0.0, (box[0] - pad_x) / scale), 1),
                        round(max(0.0, (box[1] - pad_y) / scale), 1),
                        round(min(float(image.width), (box[2] - pad_x) / scale), 1),
                        round(min(float(image.height), (box[3] - pad_y) / scale), 1),
                    ],
                })

    print(json.dumps({"counts": counts, "detections": detections}))


if __name__ == "__main__":
    main()
