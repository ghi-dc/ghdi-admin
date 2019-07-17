<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:tei="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="tei"
  version="2.0">

  <xsl:import href="dta-base.xsl"/>
  <xsl:import href="dta-customize-scalar.xsl"/>

  <!-- notes are placed 'perpage' for source and 'end' for topic/interpretation -->
  <xsl:param name="noteplacement" select="'perpage'" />

  <xsl:output method="html" doctype-system=""/>

  <!-- match root -->
  <xsl:template match="/">
    <xsl:if test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:notesStmt/tei:note">
      <div class="introduction">
        <xsl:apply-templates select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:notesStmt/tei:note/node()"/>
      </div>
    </xsl:if>

    <xsl:apply-templates />
  </xsl:template>
</xsl:stylesheet>
