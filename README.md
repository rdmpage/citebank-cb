# CiteBank - bibliographic database using CSL-JSON and CouchDB

CiteBank provides a “dirty bucket” of publications, “dirty” because it accepts input from multipe sources of possibly varying quality. The motivation for this project is that we lack a comprehensive database of the taxonomic literature. Databases such as Google Scholar and OpenAlex are huge, but their coverage of taxonomy is weak, especially for older or more obscure literature. 

Hence in CiteBank we build our own database. Potential sources include CrossRef and other DOI resolving agencies, lists of literature cited in academic papers, Wikispecies and related projects, and taxonomic databases. Hence CiteBank can be thought of as “crowd sourcing” a database of taxonomic literature. 

## Database

The data is stored in CouchDB, which is a JSON-native document database. 



## Data format

CiteBank uses CSL-JSON to represent bibliographic records. This is the format used by tools to display citations in various formats, and is supported by most DOI resolution agencies (via content negotiation).

## Sources

### DOI resolution

DOis for publications can, in many cases, be resolved to CSL-JSON  using content negotiation. If the HTTP `Accept` header is set to `application/vnd.citationstyles.csl+json` then there is a good chance you will get CSL-JSON back. CrossRef is an excellent example of this.

### JATS XML

If a publication is available in JATS XML (e.g., Pensoft journals, PubMed Central open access content) then the literature cited is likely to be marked up and these references can be extracted and converted to CSL-JSON.

Note that markup can be incorrect or inadequate. For example, this record from https://doi.org/10.3897/zookeys.834.28800 doesn’t mark up the title or journal. At the moment records like this are accepted.

```
<ref id="B16">
  <mixed-citation xlink:type="simple">
  <person-group>
    <name name-style="western">
      <surname>Benson</surname>
      <given-names>WH</given-names>
    </name>
  </person-group>(
  <year>1856b</year>) Descriptions of one Indian and nine new Burmese Helices; and notes on two Burmese 
  <tp:taxon-name>
    <tp:taxon-name-part taxon-name-part-type="superfamily">Cyclostomacea</tp:taxon-name-part>
  </tp:taxon-name>. Annals and Magazine of Natural History, Series 2, 18: 249–254. 
  <ext-link xlink:type="simple" ext-link-type="doi" xlink:href="10.1080/00222935608697626">https://doi.org/10.1080/00222935608697626</ext-link></mixed-citation>
</ref>
```

### HTML

If an article webpage supports Google Scholar tags then it may well have the literature cited included in individual `<meta name="citation_reference" content="…">` tags. Each reference will be an unstructured text string, so we need a citation parser such as https://github.com/rdmpage/citation-parsing to convert the string to CSL-JSON.

### Text

In some cases lists of literature cited may be extracted from PDFs and converted to text. If each reference is on a single line, these references can be parsed into CSL-JSON.

## Clustering

Given that the same reference may be found in multiple sources, CiteBank includes automated tools to cluster records that are likely to be the same, and can construct a consensus of those records.

## Running automated clustering script locally

```
do php /Users/rpage/Sites/citebank-cb/worker.php; sleep 1; done
```

## Clustering cases to look at

### Zur Kenntnis javanischer Agromyzinen

Records from Naturalis, CrossRef, and Zookeys citation

Note also Naturalis has more detailed breakdown of author's name:

```
"author": [
    {
      "family": "Meijere",
      "initials": "",
      "given": "J.C.H.",
      "non-dropping-particle": "de"
    }
  ],
```

67bf7c05d1e9df3b1832d6ae00ed4419 Meijere, J. C. H. (1922). Zur Kenntnis javanischer Agromyzinen. In Bijdragen tot de Dierkunde (Vol. 22, pp. 18–23). 
a59ec6db90dbe82800765cb4ba938bcf de Meijere, J. C. H. (1922). Zur Kenntnis Javanischer Agromyzinen. In Bijdragen tot de dierkunde (Vol. 22, Issue 1, pp. 17–24). 
https%3A%2F%2Fdoi.org%2F10.1163%2F26660644-02201004 de Meijere, J. C. H. (1922). Zur Kenntnis Javanischer Agromyzinen. In Bijdragen tot de Dierkunde (Vol. 22, Issue 1, pp. 17–24). https://doi.org/10.1163/26660644-02201004
