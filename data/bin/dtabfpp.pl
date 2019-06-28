#!/usr/bin/perl

=encoding utf8

=head1 NAME

dtabfpp.pl -- pretty printing for DTABf documents.

=head1 SYNOPSIS

dtabfpp.pl < INFILE > OUTFILE

=head1 DESCRIPTION

Pretty printing of a DTABF XML file, according to rules which are
specified for L<XML::LibXML::PrettyPrint>.

C<< <lb/> >> elements are put on the end of each line.

=head1 REQUIREMENTS

=over 8

=item L<XML::LibXML>

=item L<XML::LibXML::PrettyPrint>

=back

=head1 TODO

Almost everything.

=head1 SEE ALSO

=over 8

=item DTABf documentation

L<http://www.deutschestextarchiv.de/doku/basisformat/uebersichtHeader.html>,
L<http://www.deutschestextarchiv.de/doku/basisformat/uebersichtText.html>.

=item L<XML::LibXML::PrettyPrint>

=back

=head1 AUTHOR

Frank Wiegand, C<< <wiegand at bbaw.de> >>, 2019.

=cut

use warnings;
use 5.012;
use XML::LibXML::PrettyPrint;

# CAVEAT: The following HoAs are merged into a single structure.
# For conflicting entries please modify the corresponding callback subs.

# elements within <teiHeader>
my $element_header = {
    inline   => [qw(abbr address bibl biblScope country date docDate edition email gap idno language orgName pubPlace publisher ref title)],
    block    => [qw()],
    compact  => [qw(addName classCode forename measure rendition surname head)],
    preserves_whitespace => [qw()],
};

# elements within <text>
my $element_text = {
    inline   => [qw(abbr expan hi)],
    block    => [qw()],
    compact  => [qw()],
    preserves_whitespace => [qw()],
};

my $cb_inline = sub {
    my $node = shift;

    # inline elements within <teiHeader>
    if ( $node->nodeName =~ /^(?:note)$/ ) {
        my $parent = $node->parentNode;
        while ( $parent ) {
            if ( $parent->nodeName eq 'teiHeader' ) {
                return 1;
            }
            $parent = $parent->parentNode;
        }
    }

    # inline elements within <text>
    if ( $node->nodeName =~ /^(?:note|persName|placeName)$/ ) {
        my $parent = $node->parentNode;
        while ( $parent ) {
            # note sure why we can end up at '#document-fragment' instead of getting up to 'text'
            if ( $parent->nodeName eq 'text' or $parent->nodeName eq '#document-fragment') {
                return 1;
            }
            $parent = $parent->parentNode;
        }
    }
    return undef;
};

my $cb_block = sub {
    my $node = shift;
    # format <idno> as block when it is the outside <idno> container
    if ( $node->nodeName eq 'idno' and $node->parentNode->nodeName ne 'idno' ) {
        return 1;
    }
    return undef;
};

my $cb_compact = sub {
    my $node = shift;
    return undef;
};

my $in = do { local $/; <> };
my $document = XML::LibXML->load_xml(string => $in);
my $pp = XML::LibXML::PrettyPrint->new(
    indent_string => '    ', # 4 spaces as indentation level
    element => {
        inline   => [ @{$element_header->{inline}}, @{$element_text->{inline}}, $cb_inline ],
        block    => [ @{$element_header->{block}}, @{$element_text->{block}}, $cb_block ],
        compact  => [ @{$element_header->{compact}}, @{$element_text->{compact}}, $cb_compact ],
        preserves_whitespace => [ @{$element_header->{preserves_whitespace}}, @{$element_text->{preserves_whitespace}} ],
    }
);
$pp->pretty_print($document);
my $out = $document->toString;
$out =~ s{\p{Zs}+(<lb\b[^/]*/>)}{$1}g; # dbu: use \p{Zs} instead of \s which seems not to be Unicode safe
print $out;
