{
  "_id": "_design/search",
  "nouveau": {
    "full-text": {
      "index": "function(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  // title\n  if (doc.title) {\n    if (Array.isArray(doc['title'])) {\n      index('text', 'title', doc['title'][0]);\n    } else {\n     index('text', 'title', doc.title);\n    }\n  }\n}"
    }
  }
}