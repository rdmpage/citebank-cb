{
  "_id": "_design/citebank",
  "views": {
    "source": {
      "reduce": "_sum",
      "map": "function (doc) {\n  if (doc.citebank.source) {\n     emit(doc.citebank.source, 1);\n  }\n}"
    }
  },
  "language": "javascript"
}