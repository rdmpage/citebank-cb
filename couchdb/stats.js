{
  "_id": "_design/stats",
  "views": {
    "works": {
      "reduce": "_count",
      "map": "function(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  emit ('works', 1);\n  \n  if (doc._id == doc.citebank.cluster) {\n    emit('clusters', 1);\n  }\n}"
    }
  },
  "language": "javascript"
}