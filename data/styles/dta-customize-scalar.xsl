<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:tei="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="tei"
  version="2.0">

  <!-- translate-layer -->
  <xsl:param name="lang" />
  <xsl:variable name="strings" select="document('translation.xml')/strings"/>

  <xsl:template name="translate">
    <xsl:param name="label" />
    <xsl:choose>
      <xsl:when test="$strings/string[@key=$label and @language=$lang]">
        <xsl:value-of select="$strings/string[@key=$label and @language=$lang]" />
      </xsl:when>
      <xsl:otherwise>
        <xsl:value-of select="$label" />
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <!-- put expansions in brackets for print -->
  <xsl:template match="tei:choice">
    <xsl:choose>
      <xsl:when test="./tei:reg">
        <xsl:element name="span">
          <xsl:attribute name="title">Original: <xsl:apply-templates select="tei:orig" mode="choice"/></xsl:attribute>
          <xsl:attribute name="class">dta-reg</xsl:attribute>
          <xsl:apply-templates select="tei:reg" mode="choice"/>
        </xsl:element>
      </xsl:when>
      <xsl:when test="./tei:abbr">
        <xsl:element name="span">
          <!--<xsl:attribute name="class">dta-abbr</xsl:attribute>-->
          <xsl:apply-templates select="tei:abbr" mode="choice"/> (<xsl:apply-templates select="tei:expan" mode="choice"/>)
        </xsl:element>
      </xsl:when>
      <xsl:when test="./tei:corr">
        <xsl:element name="span">
          <xsl:attribute name="title">Schreibfehler: <xsl:apply-templates select="tei:sic" mode="choice"/></xsl:attribute>
          <xsl:attribute name="class">dta-corr</xsl:attribute>
          <xsl:apply-templates select="tei:corr" mode="choice"/>
        </xsl:element>
      </xsl:when>
    </xsl:choose>
  </xsl:template>

  <xsl:template match='tei:ref'>
    <xsl:choose>
      <xsl:when test="@target != ''">
        <xsl:choose>
          <xsl:when test="@type = 'editorialNote'">
            <span class="glossary">
              <xsl:attribute name="data-title"><xsl:value-of select="substring(@target, 2)" /></xsl:attribute>
              <xsl:apply-templates/>
            </span>
          </xsl:when>
          <xsl:otherwise>
            <a>
              <xsl:attribute name="href"><xsl:value-of select="@target" /></xsl:attribute>
              <xsl:apply-templates/>
            </a>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:when>
      <xsl:otherwise><xsl:value-of select="@target" /><xsl:apply-templates/></xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <!-- renditions -->
  <xsl:template match="tei:hi|tei:del">
    <xsl:choose>
    <xsl:when test="contains(@rendition,'#right') or contains(@rendition,'#et') or ends-with(@rendition,'#c')">
        <!-- mpdf doesn't respect display:block for span,
        so we need to use <div> instead of <span>-->
        <xsl:element name="div">
          <xsl:call-template name="applyRendition"/>
          <xsl:apply-templates/>
        </xsl:element>
    </xsl:when>
    <xsl:otherwise>
        <!-- we want actual tags instead of span with classes -->
        <xsl:call-template name="wrapRenditions">
          <xsl:with-param name="renditions" select="tokenize(@rendition, '\s+')"/>
          <xsl:with-param name="node" select="node()" />
        </xsl:call-template>
    </xsl:otherwise>
    </xsl:choose>
  </xsl:template>
  <!-- end renditions -->

  <xsl:template name="wrapRenditions">
    <xsl:param name="renditions"/>
    <xsl:param name="node"/>
    <xsl:choose>
      <xsl:when test="count($renditions) = 0"><xsl:apply-templates select="$node" /></xsl:when>
      <xsl:otherwise>
        <xsl:variable name="element"><xsl:call-template name="renditionToElement"><xsl:with-param name="rendition" select="$renditions[1]" /></xsl:call-template></xsl:variable>
        <xsl:choose>
          <xsl:when test="$element != ''">
          <xsl:element name="{$element}">
            <xsl:call-template name="wrapRenditions">
              <xsl:with-param name="renditions" select="$renditions[position() > 1]"/>
              <xsl:with-param name="node" select="$node" />
            </xsl:call-template>
          </xsl:element>
          </xsl:when>
          <xsl:otherwise><xsl:apply-templates select="$node" /></xsl:otherwise>
        </xsl:choose>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <xsl:template name="renditionToElement">
    <xsl:param name="rendition"/>
    <xsl:choose>
      <xsl:when test="$rendition='#b'">b</xsl:when>
      <xsl:when test="$rendition='#i'">i</xsl:when>
      <xsl:otherwise></xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <!-- begin marginals -->
  <!-- mpdf doesn't respect display:block for span,
  so we need to use <div> instead of <span>-->
  <xsl:template match='tei:note[@place="right" and not(@type)]'>
    <xsl:value-of select="@n"/>
    <div class="dta-marginal dta-marginal-right">
      <xsl:apply-templates/>
    </div>
  </xsl:template>

  <xsl:template match='tei:note[@place="left" and not(@type)]'>
    <xsl:value-of select="@n"/>
    <div class="dta-marginal dta-marginal-left">
      <xsl:apply-templates/>
    </div>
  </xsl:template>
  <!-- end marginals -->


  <!-- begin footnotes -->
  <xsl:template match='tei:note[@place="foot"]'>
    <xsl:if test="string-length(@prev)=0">
      <a class="dta-fn-intext">
        <xsl:attribute name="name">note-<xsl:number level="any" count='//tei:note[@place="foot" and (text() or *)]' format="1"/>-marker</xsl:attribute>
        <xsl:attribute name="href">#note-<xsl:number level="any" count='//tei:note[@place="foot" and (text() or *)]' format="1"/></xsl:attribute>
        <xsl:choose>
            <!-- manually numbered -->
            <xsl:when test="@n">
                <xsl:value-of select="@n"/>
            </xsl:when>
            <xsl:otherwise>
              <xsl:choose>
                <xsl:when test="$noteplacement = 'perpage'">
                  <xsl:number level="any" count='//tei:note[@place="foot" and (text() or *) and not(@n)]' format="a"/>
                </xsl:when>
                <xsl:otherwise>
                  <xsl:number level="any" count='//tei:note[@place="foot" and (text() or *) and not(@n)]' format="[1]"/>
                </xsl:otherwise>
              </xsl:choose>
            </xsl:otherwise>
        </xsl:choose>
      </a>
      <!--<xsl:text> </xsl:text>-->
    </xsl:if>
  </xsl:template>

  <!-- show at end -->
  <xsl:template  match='tei:note[@place="foot"]' mode="footnotes">
    <xsl:choose>
      <!-- occurance at the end (content of the endnote) -->
      <xsl:when test="string-length(.) &gt; 0">
        <xsl:choose>
          <!-- doesn't contain pagebreak -->
          <xsl:when test="local-name(*[1])!='pb'">
            <div class="dta-endnote dta-endnote-indent">
              <a class="dta-fn-sign">
                <xsl:attribute name="name">note-<xsl:number level="any" count='//tei:note[@place="foot" and (text() or *)]' format="1"/></xsl:attribute>
                <xsl:attribute name="href">#note-<xsl:number level="any" count='//tei:note[@place="foot" and (text() or *)]' format="1"/>-marker</xsl:attribute>
                    <xsl:choose>
                        <!-- manually numbered -->
                        <xsl:when test="@n">
                            <xsl:value-of select="@n"/>
                        </xsl:when>
                        <xsl:otherwise>
                          <xsl:choose>
                            <xsl:when test="$noteplacement = 'perpage'">
                              <xsl:number level="any" count='//tei:note[@place="foot" and (text() or *) and not(@n)]' format="a"/>
                            </xsl:when>
                            <xsl:otherwise>
                              <xsl:number level="any" count='//tei:note[@place="foot" and (text() or *) and not(@n)]' format="[1]"/>
                            </xsl:otherwise>
                          </xsl:choose>
                        </xsl:otherwise>
                    </xsl:choose>
              </a>
              <xsl:text> </xsl:text>
              <xsl:apply-templates/>
            </div>
          </xsl:when>
          <!-- contains pagebreak -->
          <xsl:otherwise>
            <div class="dta-endnote">
              <xsl:apply-templates/>
            </div>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:when>
      <!-- occurence in text (link to the endnote) -->
      <xsl:otherwise>
        <span class="dta-fn-sign">
          <!--
          <xsl:value-of select="@n"/>
          -->
                  <xsl:number level="any" count='//tei:note[@place="foot" and text()]' format="[1]"/>

        </span>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>
  <!-- end end notes -->

  <!-- inline notes -->
    <xsl:template match='tei:note[@place="inline"]'>
        <span class="inline">
            <xsl:if test="@type='editorial'">
                <xsl:attribute name="class">editorial inline</xsl:attribute>
            </xsl:if>
            <xsl:apply-templates/>
        </span>
    </xsl:template>

  <!-- copied from dtabf_base.xsl to act as in dtabf_viewer.xsl -->
  <!-- we don't separate note-handling -->
  <xsl:template match="tei:text[not(descendant::tei:text)]">
    <xsl:apply-templates/>
    <xsl:if test="$noteplacement='perpage' and //tei:note[@place='foot' and string-length(@prev) >= 0][not(./following::tei:pb)]">
      <!-- notes for the last page -->
      <hr />
      <xsl:for-each select="//tei:note[@place='foot' and string-length(@prev) > 0][not(./following::tei:pb)]">
        <xsl:apply-templates select="." mode="footnotes"/>
      </xsl:for-each>
      <xsl:for-each select="//tei:note[@place='foot' and string-length(@prev) = 0][not(./following::tei:pb)]">
        <xsl:apply-templates select="." mode="footnotes"/>
      </xsl:for-each>
    </xsl:if>
    <xsl:apply-templates select='//tei:fw[@place="bottom" and (text() or *)]' mode="signatures"/>
  </xsl:template>

  <xsl:template match='tei:pb'>
    <xsl:variable name="thisSite" select="."/>
    <xsl:if test="preceding::tei:note[@place='foot'][./preceding::tei:pb[. is $thisSite/preceding::tei:pb[1]]]">
      <!-- notes for the current page -->
      <hr />
      <xsl:for-each select="preceding::tei:note[@place='foot' and string-length(@prev) > 0][./preceding::tei:pb[. is $thisSite/preceding::tei:pb[1]]]">
        <xsl:apply-templates select="." mode="footnotes"/>
      </xsl:for-each>
      <xsl:for-each select="preceding::tei:note[@place='foot' and string-length(@prev) = 0][./preceding::tei:pb[. is $thisSite/preceding::tei:pb[1]]]">
        <xsl:apply-templates select="." mode="footnotes"/>
      </xsl:for-each>
    </xsl:if>
    <div class="dta-pb"><!--<xsl:value-of select="@facs"/>--><xsl:if test="@n"><!-- : --><xsl:value-of select="@n"/> </xsl:if></div>
    <br />
  </xsl:template>

  <!-- adapted from dtabf_base.xsl, expand for print -->
  <xsl:template match="tei:choice">
    <xsl:choose>
      <xsl:when test="./tei:reg">
        <xsl:apply-templates select="tei:orig"/>
        <xsl:element name="span">
          <xsl:attribute name="class">dta-reg</xsl:attribute>
          [<xsl:value-of select="tei:reg"/>]
        </xsl:element>
      </xsl:when>
      <xsl:when test="./tei:abbr">
      <xsl:apply-templates select="tei:abbr"/>
        <xsl:element name="span">
          <xsl:attribute name="class">dta-abbr</xsl:attribute>
          [<xsl:variable name="temp"><xsl:apply-templates select="tei:expan" mode="choice"/></xsl:variable><xsl:value-of select="normalize-space($temp)" />]
        </xsl:element>
      </xsl:when>
      <xsl:otherwise>
        <xsl:element name="span">
          <xsl:attribute name="title">Schreibfehler: <xsl:value-of select="tei:sic"/></xsl:attribute>
          <xsl:attribute name="class">dta-corr</xsl:attribute>
          <xsl:apply-templates select="tei:corr"/>
        </xsl:element>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <!-- from http://www.deutschestextarchiv.de/basisformat_ms.rng -->
  <xsl:template match="tei:del">
    <xsl:element name="span">
      <xsl:call-template name="applyRendition"/>
      <xsl:apply-templates/>
    </xsl:element>
  </xsl:template>

<xsl:template match='tei:gap'>
  <span class="gap">
    <xsl:text>[</xsl:text>
    <xsl:if test="@reason='lost'"><xsl:call-template name="translate">
      <xsl:with-param name="label" select="'verlorenes Material'" />
    </xsl:call-template></xsl:if>
    <xsl:if test="@reason='insignificant' or not(@reason)"><span><xsl:attribute name="title">
        <xsl:call-template name="translate">
            <xsl:with-param name="label" select="'irrelevantes Material'" />
        </xsl:call-template>
      </xsl:attribute>&#x2026;</span></xsl:if>
    <xsl:if test="@reason='fm'">fremdsprachliches Material</xsl:if>
    <xsl:if test="@reason='illegible'"><xsl:call-template name="translate">
              <xsl:with-param name="label" select="'unleserliches Material'" />
    </xsl:call-template></xsl:if>
    <xsl:if test="@unit"><xsl:text> – </xsl:text></xsl:if>
    <xsl:choose>
      <xsl:when test="@unit">
        <xsl:if test="@quantity">
          <xsl:value-of select="@quantity"/><xsl:text> </xsl:text>
        </xsl:if>
        <xsl:choose>
          <xsl:when test="@unit='pages' and @quantity!=1">Seiten</xsl:when>
          <xsl:when test="@unit='pages' and @quantity=1">Seite</xsl:when>
          <xsl:when test="@unit='lines' and @quantity!=1">Zeilen</xsl:when>
          <xsl:when test="@unit='lines' and @quantity=1">Zeile</xsl:when>
          <xsl:when test="@unit='words' and @quantity!=1">Wörter</xsl:when>
          <xsl:when test="@unit='words' and @quantity=1">Wort</xsl:when>
          <xsl:when test="@unit='chars'">Zeichen</xsl:when>
        </xsl:choose>
        <xsl:text> fehl</xsl:text>
        <xsl:if test="@quantity=1 or not(@quantity)">t</xsl:if>
        <xsl:if test="@quantity!=1">en</xsl:if>
      </xsl:when>
    </xsl:choose>
    <xsl:text>]</xsl:text>
  </span>
</xsl:template>

  <xsl:template match="tei:foreign">
    <span class="dta-foreign">
     <xsl:if test="@dir">
       <xsl:attribute name="dir"><xsl:value-of select="@dir"/></xsl:attribute>
     </xsl:if>
     <xsl:attribute name="title">
        <xsl:call-template name="translate">
            <xsl:with-param name="label" select="'fremdsprachliches Material'" />
        </xsl:call-template>
     </xsl:attribute>
     <xsl:choose>
      <xsl:when test="@xml:lang">
        <xsl:attribute name="xml:lang">
          <xsl:value-of select="@xml:lang"/>
        </xsl:attribute>
        <xsl:choose>
          <xsl:when test="not(child::*) and not(child::text())">
            <xsl:text>FM: </xsl:text>
            <xsl:choose>
              <xsl:when test="@xml:lang='he' or @xml:lang='heb' or @xml:lang='hbo'">
                <xsl:text>hebräisch</xsl:text>
              </xsl:when>
              <xsl:when test="@xml:lang='el' or @xml:lang='grc' or @xml:lang='ell'">
                <xsl:text>griechisch</xsl:text>
              </xsl:when>
              <xsl:otherwise>
                <xsl:value-of select="@xml:lang"/>
              </xsl:otherwise>
            </xsl:choose>
          </xsl:when>
          <xsl:otherwise>
            <xsl:apply-templates/>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:when>
       <xsl:otherwise>
         <xsl:apply-templates/>
       </xsl:otherwise>
     </xsl:choose>
    </span>
  </xsl:template>

<xsl:template match='tei:cb'>
  <div class="dta-cb">
    <xsl:choose>
      <xsl:when test="@type='start'">[<xsl:call-template name="translate">
              <xsl:with-param name="label" select="'Beginn Spaltensatz'" />
    </xsl:call-template>]</xsl:when>
      <xsl:when test="@type='end'">[<xsl:call-template name="translate">
              <xsl:with-param name="label" select="'Ende Spaltensatz'" />
    </xsl:call-template>]</xsl:when>
      <xsl:otherwise>[<xsl:call-template name="translate">
              <xsl:with-param name="label" select="'Spaltenumbruch'" />
    </xsl:call-template>]</xsl:otherwise>
    </xsl:choose>
  </div>
</xsl:template>

  <!-- from dta-base.xsl - but no lb after head required -->
  <xsl:template match="tei:head">
    <xsl:choose>
      <!-- if embedded in a <figure>: create span (figdesc) -->
      <xsl:when test="ancestor::tei:figure">
        <span>
          <xsl:call-template name="applyRendition">
            <xsl:with-param name="class" select="'dta-figdesc'"/>
          </xsl:call-template>
          <xsl:apply-templates/>
        </span>
      </xsl:when>
      <!-- if embedded in a <list> or child of <lg>: create div-block (dta-head) -->
      <xsl:when test="ancestor::tei:list or parent::tei:lg">
        <div>
          <xsl:call-template name="applyRendition">
            <xsl:with-param name="class" select="'dta-head'"/>
          </xsl:call-template>
          <xsl:apply-templates/>
        </div>
      </xsl:when>
      <!-- if no <lb/> at the end or after the head: embed directly
      <xsl:when
        test="(local-name(./*[position()=last()]) != 'lb' or normalize-space(./tei:lb[position()=last()]/following-sibling::text()[1]) != '') and local-name(following::*[1]) != 'lb'">
        <xsl:apply-templates/>
      </xsl:when> -->
      <xsl:otherwise>
        <xsl:choose> <!-- TODO: why the second choose? -->
          <xsl:when test="parent::tei:div/@n or parent::tei:div">
            <xsl:choose>
              <!-- if the embedding div-block's n-attribute is greater 6 or does not exist: create div-block (dta-head)  -->
              <xsl:when test="parent::tei:div/@n > 6 or not(parent::tei:div/@n)">
                <div>
                  <xsl:call-template name="applyRendition">
                    <xsl:with-param name="class" select="'dta-head'"/>
                  </xsl:call-template>
                  <xsl:apply-templates/>
                </div>
              </xsl:when>
              <!-- if the embedding div-block's n-attribute is lesser than 7: create h(@n)-block -->
              <xsl:otherwise>
                <xsl:element name="h{parent::tei:div/@n}">
                  <xsl:call-template name="applyRendition">
                    <xsl:with-param name="class" select="'dta-head'"/>
                  </xsl:call-template>
                  <xsl:apply-templates/>
                </xsl:element>
              </xsl:otherwise>
            </xsl:choose>
          </xsl:when>
          <!-- WARNING: never used (because of xsl:when test="ancestor::tei:list above -->
          <xsl:when test="parent::tei:list">
            <xsl:apply-templates/>
          </xsl:when>
          <!-- default -->
          <xsl:otherwise>
            <h2>
              <xsl:call-template name="applyRendition"/>
              <xsl:apply-templates/>
            </h2>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <!-- add shared overrides and extensions to the dtabf-base rules -->
  <xsl:template match="tei:sic">
    <xsl:apply-templates/> [sic]<!--
--></xsl:template>

  <xsl:template match='tei:persName'>
    <xsl:call-template name="entity-ref">
      <xsl:with-param name="value">
        <xsl:value-of select="@ref"/>
      </xsl:with-param>
      <xsl:with-param name="type">person</xsl:with-param>
    </xsl:call-template>
  </xsl:template>

  <xsl:template match='tei:placeName'>
    <xsl:call-template name="entity-ref">
      <xsl:with-param name="value">
        <xsl:value-of select="@ref"/>
      </xsl:with-param>
      <xsl:with-param name="type">place</xsl:with-param>
    </xsl:call-template>
  </xsl:template>

  <xsl:template match='tei:orgName'>
    <xsl:call-template name="entity-ref">
      <xsl:with-param name="value">
        <xsl:value-of select="@ref"/>
      </xsl:with-param>
      <xsl:with-param name="type">organization</xsl:with-param>
    </xsl:call-template>
  </xsl:template>

  <xsl:template match="tei:date">
    <xsl:call-template name="entity-ref">
      <xsl:with-param name="value">
        <xsl:value-of select="@corresp"/>
      </xsl:with-param>
      <xsl:with-param name="type">date</xsl:with-param>
    </xsl:call-template>
  </xsl:template>

  <xsl:template name="entity-ref">
    <xsl:param name="value"/>
    <xsl:param name="type"/>
    <xsl:choose>
      <xsl:when test="$value and starts-with($value,'http')">
        <xsl:element name="span">
          <xsl:attribute name="class">entity-ref</xsl:attribute>
          <xsl:attribute name="data-type"><xsl:value-of select="$type" /></xsl:attribute>
          <xsl:attribute name="data-uri">
            <xsl:choose>
              <xsl:when test="contains($value,' ')">
                <xsl:value-of select="substring-before($value,' ')"/>
              </xsl:when>
              <xsl:otherwise>
                <xsl:value-of select="$value"/>
              </xsl:otherwise>
            </xsl:choose>
          </xsl:attribute>
          <xsl:apply-templates/>
        </xsl:element>
      </xsl:when>
      <xsl:otherwise>
        <xsl:apply-templates/>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:template>

  <xsl:template match="tei:bibl">
    <span>
      <xsl:if test="@corresp">
        <xsl:attribute name="data-corresp" select="@corresp" />
      </xsl:if>
      <xsl:call-template name="applyRendition">
        <xsl:with-param name="class" select="'dta-bibl'"/>
      </xsl:call-template>
      <xsl:apply-templates/>
    </span>
  </xsl:template>

  <!-- override version from dta-base.xsl without adding classes -->
  <xsl:template match="tei:p">
    <p>
      <xsl:if test="@dir">
       <xsl:attribute name="dir"><xsl:value-of select="@dir"/></xsl:attribute>
      </xsl:if>
      <xsl:apply-templates/>
    </p>
  </xsl:template>

<xsl:template match='tei:figure'>
  <!-- currently just supporting the scalar embed of a single full-sized scalar-inline-media per document -->
  <a data-size="full" data-align="left" data-caption="description" data-annotations="" class="inline" name="scalar-inline-media" href="#"><xsl:attribute name="resource" select='replace(//tei:idno[@type="DTAID"], "(.*)(image|map)-(\d+)", "media/$2-$3")' /></a>
</xsl:template>

</xsl:stylesheet>
